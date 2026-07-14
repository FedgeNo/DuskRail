<?php

declare(strict_types=1);

/**
 * Opens a GET request to a URL's host:port via cURL, reads the response
 * status line + headers, then pauses the transfer before any body bytes
 * arrive - the connection stays open, not closed, so a caller can decide
 * (from the status code/Content-Type/Content-Length alone) whether it's
 * worth reading the body at all before resuming.
 */
class HTTPConnection
{
    private \CurlMultiHandle $multiHandle;
    private \CurlHandle $easyHandle;
    private bool $headersComplete = false;

    public ?int $statusCode = null;
    public array $headers = [];
    private string $body = '';
    private bool $bodyRead = false;

    public function __construct(URL $url)
    {
        $this -> easyHandle = curl_init();

        curl_setopt_array($this -> easyHandle, [
            CURLOPT_URL => $url -> toString(),
            CURLOPT_PORT => $url -> port,
            CURLOPT_HTTPGET => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'DuskRail/0.1',
            CURLOPT_HEADERFUNCTION => $this -> onHeaderLine(...),
            // Not read until readBody() resumes the transfer - curl_pause()
            // in onHeaderLine() stops delivery here before any real content
            // arrives, but the callback still needs to exist meanwhile.
            CURLOPT_WRITEFUNCTION => function (\CurlHandle $ch, string $chunk): int {
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

    private function onHeaderLine(\CurlHandle $ch, string $line): int
    {
        $trimmed = rtrim($line, "\r\n");

        if ($trimmed === '') {
            // Blank line marks the end of the headers - pause here, before
            // curl moves on to delivering body bytes to CURLOPT_WRITEFUNCTION.
            curl_pause($ch, CURLPAUSE_ALL);
            $this -> headersComplete = true;

            return strlen($line);
        }

        if ($this -> statusCode === null && preg_match('#^HTTP/\S+\s+(\d+)#', $trimmed, $match)) {
            $this -> statusCode = (int) $match[1];

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
