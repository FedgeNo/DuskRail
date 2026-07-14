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
            // Body bytes aren't read yet - curl_pause() in onHeaderLine()
            // stops the transfer before this is ever called for real content,
            // but it still needs to exist and report bytes consumed.
            CURLOPT_WRITEFUNCTION => fn (\CurlHandle $ch, string $chunk): int => strlen($chunk),
        ]);

        $this -> multiHandle = curl_multi_init();
        curl_multi_add_handle($this -> multiHandle, $this -> easyHandle);

        $this -> pullUntilHeadersComplete();
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
