<?php

declare(strict_types=1);

/**
 * Opens a GET request to a URL's host:port via cURL, reads the response
 * status line + headers, then pauses the transfer before any body bytes
 * arrive - the connection stays open, not closed, so a caller can decide
 * (from the status code/Content-Type/Content-Length alone) whether it's
 * worth reading the body at all before resuming.
 *
 * Real page/image crawling goes through ChromeConnection instead (a genuine
 * request from the shared persistent Chrome instance, not an approximation
 * of one) - this class is left for fetches that aren't actually crawler
 * traffic against the target site at all (Host::fetchRobotsTxtIfMissing(),
 * TLDs.php's one-time IANA list fetch), where a plain, fast cURL GET is all
 * that's needed. Still sent with a real-Chrome-shaped header set rather than
 * a bare User-Agent, since there's no reason to make even these fetches look
 * unlike anything else this crawler sends.
 */
class HTTPConnection
{
    // Deliberately generous - a page can be extremely markup-heavy (nested
    // divs, inline SVGs, huge inlined scripts before removeStyleAndScriptTags()
    // strips them) and still only contain a perfectly reasonable amount of
    // actual text. This is a backstop against truly extreme/malicious
    // responses, not a tight budget.
    private const MAX_BODY_SIZE = 20 * 1024 * 1024;

    // Kept to a single recent, real, common desktop build rather than
    // parameterized/rotated - a fleet of "different" UAs from the same
    // crawler is its own tell, and one plausible, current identity is all
    // "looks like a real browser" actually requires.
    private const CHROME_VERSION = '149';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/' . self::CHROME_VERSION . '.0.0.0 Safari/537.36';

    private \CurlMultiHandle $multiHandle;
    private \CurlHandle $easyHandle;
    private bool $headersComplete = false;

    public ?int $statusCode = null;
    public array $headers = [];
    public bool $bodyTruncated = false;
    private string $body = '';
    private bool $bodyRead = false;

    public function __construct(URL $url)
    {
        $this -> easyHandle = curl_init();

        curl_setopt_array($this -> easyHandle, [
            CURLOPT_URL => $url -> toString(),
            CURLOPT_PORT => $url -> port,
            CURLOPT_HTTPGET => true,
            // Redirects are never followed automatically. A Location header
            // is chosen entirely by the host being fetched, and following one
            // without looking is how a sitemap that passed every same-host
            // check ends up pointing this process at localhost. Callers that
            // want the hop take it themselves, against the same rules the
            // original URL was held to (see Sitemap::ingestFor()).
            CURLOPT_FOLLOWLOCATION => false,
            // Belt and braces alongside that: even a caller that turns
            // following back on can only ever be redirected to the two
            // schemes this project fetches, never to a file:// or any other
            // protocol this build of curl happens to support.
            CURLOPT_PROTOCOLS_STR => 'http,https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'http,https',
            CURLOPT_CONNECTTIMEOUT => 10,
            // A connect timeout alone only covers getting the connection open
            // - a server that accepts it and then sends nothing would hold
            // this open indefinitely, hanging the crawler worker until
            // bin/crawler-manager.php SIGKILLs it and charges the item a hang
            // strike for a stall that was never the item's fault. This covers
            // the whole transfer, including the window it sits paused in
            // between the constructor and readBody() - fine, since every
            // caller resumes immediately.
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => self::chromeHeaders(),
            // Without this, curl sends no Accept-Encoding at all - but some
            // servers gzip the response anyway regardless (confirmed: a real
            // robots.txt fetch came back as raw gzip bytes, which then
            // failed to insert as invalid UTF-8). An empty string means
            // "accept and auto-decode every encoding curl was built with"
            // (gzip, deflate, br, ...), so the body handed to $this -> body is
            // always the real decompressed content either way. Real Chrome's
            // Accept-Encoding also lists "zstd" - omitted here since this
            // build of curl can't decode it, and requesting an encoding it
            // can't turn back into the real body would be worse than the
            // minor mismatch of not asking for it at all.
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => $this -> onHeaderLine(...),
            // Not read until readBody() resumes the transfer - curl_pause()
            // in onHeaderLine() stops delivery here before any real content
            // arrives, but the callback still needs to exist meanwhile.
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $ch, string $chunk): int {
                if (strlen($this -> body) + strlen($chunk) > self::MAX_BODY_SIZE) {
                    // Returning anything other than the chunk's real length
                    // tells curl the write failed, which aborts the transfer
                    // right here rather than continuing to buffer an
                    // unbounded response into memory.
                    $this -> bodyTruncated = true;

                    return 0;
                }

                $this -> body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $this -> multiHandle = curl_multi_init();
        curl_multi_add_handle($this -> multiHandle, $this -> easyHandle);

        $this -> pullUntilHeadersComplete();
    }

    /**
     * The parsed Content-Type header (e.g. "text/html; charset=UTF-8"), or
     * null if the response didn't send one.
     */
    public function contentType(): ?ContentType
    {
        return isset($this -> headers['content-type']) ? new ContentType($this -> headers['content-type']) : null;
    }

    /**
     * Resumes the paused transfer and reads the rest of the response body to
     * completion, closing the connection afterward - once a caller decides
     * the body's worth reading at all, there's no reason left to keep this
     * one open/pausable.
     */
    public function readBody(): string
    {
        if ($this -> bodyRead) {
            return $this -> body;
        }

        curl_pause($this -> easyHandle, CURLPAUSE_CONT);

        do {
            $status = curl_multi_exec($this -> multiHandle, $active);

            if ($active) {
                curl_multi_select($this -> multiHandle);
            }
        } while ($active && $status === CURLM_OK);

        curl_multi_remove_handle($this -> multiHandle, $this -> easyHandle);
        curl_multi_close($this -> multiHandle);
        curl_close($this -> easyHandle);

        $this -> bodyRead = true;

        return $this -> body;
    }

    /**
     * The header set (name, value, and order) a real Chrome/self::CHROME_VERSION
     * sends for a top-level navigation - the only shape this class's callers
     * ever need (robots.txt, the IANA TLD list - never an image or anything
     * fetched as a "subresource" in the first place).
     */
    private static function chromeHeaders(): array
    {
        return [
            'sec-ch-ua: "Chromium";v="' . self::CHROME_VERSION . '", "Not:A-Brand";v="24", '
                . '"Google Chrome";v="' . self::CHROME_VERSION . '"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
            'Upgrade-Insecure-Requests: 1',
            'User-Agent: ' . self::USER_AGENT,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,'
                . 'image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-User: ?1',
            'Sec-Fetch-Dest: document',
            'Accept-Language: en-US,en;q=0.9',
        ];
    }

    private function onHeaderLine(\CurlHandle $ch, string $line): int
    {
        $trimmed = rtrim($line, "\r\n");

        if ($trimmed === '') {
            // Blank line marks the end of a response's headers. With redirects
            // followed, that happens once per hop - pausing on a redirect's
            // headers would stop the transfer on the hop rather than on the
            // response that actually answers the request, so only a final
            // (non-redirect) response pauses here, before curl moves on to
            // delivering body bytes to CURLOPT_WRITEFUNCTION.
            if ($this -> statusCode !== null && $this -> statusCode >= 300 && $this -> statusCode < 400) {
                return strlen($line);
            }

            curl_pause($ch, CURLPAUSE_ALL);
            $this -> headersComplete = true;

            return strlen($line);
        }

        // A status line starts a new response - on a followed redirect that's
        // the next hop, whose headers replace the previous hop's rather than
        // merging into them.
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $trimmed, $match)) {
            $this -> statusCode = (int) $match[1];
            $this -> headers = [];

            return strlen($line);
        }

        if (str_contains($trimmed, ':')) {
            [$name, $value] = explode(':', $trimmed, 2);
            $this -> headers[strtolower(trim($name))] = trim($value);
        }

        return strlen($line);
    }

    private function pullUntilHeadersComplete(): void
    {
        do {
            $status = curl_multi_exec($this -> multiHandle, $active);

            if ($this -> headersComplete) {
                return;
            }

            if ($active) {
                curl_multi_select($this -> multiHandle);
            }
        } while ($active && $status === CURLM_OK);
    }
}
