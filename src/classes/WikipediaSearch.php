<?php

declare(strict_types=1);

final class WikipediaSearch {
    public static function search(string $query, int $limit = 10): array {
        $query = trim($query);
        if ($query === '' || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 200
            || preg_match('/[\x00-\x1f\x7f]/', $query) || $limit < 1 || $limit > 20) throw new InvalidArgumentException('Supply a query of 1–200 characters and a limit of 1–20 results.');
        $started = microtime(true);
        try {
            $result = SearchThrottle::run(VAR_DIR . '/search-throttle', static fn(): array => self::request($query, $limit));
        } catch (RuntimeException $exception) {
            $result = ['status' => 'error', 'results' => [], 'error' => $exception -> getMessage()];
        }
        return ['engine' => 'wikipedia', 'query' => $query, 'limit' => $limit, 'requestedAt' => gmdate('c', (int) $started), 'durationMs' => (int) round((microtime(true) - $started) * 1000)] + $result;
    }

    private static function request(string $query, int $limit): array {
        $addresses = IPAddress::publicAddressesFor('en.wikipedia.org');
        if ($addresses === []) throw new RuntimeException('Wikipedia did not resolve exclusively to public addresses.');
        $source = BrowserEgress::nextAddress(Env::get('BROWSER_OUTBOUND_IPS', ''), VAR_DIR . '/browser-egress-counter');
        if ($source !== null) $addresses = array_values(array_filter($addresses, static fn(string $address): bool => !str_contains($address, ':')));
        if ($addresses === []) throw new RuntimeException('Wikipedia has no destination compatible with the configured outbound address.');
        $address = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];
        $handle = curl_init('https://en.wikipedia.org/w/rest.php/v1/search/page?' . http_build_query(['q' => $query, 'limit' => $limit], '', '&', PHP_QUERY_RFC3986));
        if ($handle === false) throw new RuntimeException('Could not start the Wikipedia API request.');
        $body = '';
        $oversized = false;
        $retry_after = '';
        curl_setopt_array($handle, [
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => ['en.wikipedia.org:443:' . $address],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'DuskRail/1.0 (+' . Env::get('SITE_URL', 'https://github.com/FedgeNo/DuskRail') . ')',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retry_after): int {
                if (str_starts_with(strtolower($header), 'retry-after:')) $retry_after = trim(substr($header, 12));
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$oversized): int {
                if (strlen($body) + strlen($chunk) > 2097152) { $oversized = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if ($source !== null) curl_setopt($handle, CURLOPT_INTERFACE, $source);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($oversized) throw new RuntimeException('Wikipedia API response exceeded 2 MiB.');
        if (curl_errno($handle) !== 0) throw new RuntimeException('Wikipedia API network error ' . curl_errno($handle) . ': ' . curl_error($handle));
        if ($status !== 200) return ['status' => in_array($status, [403, 429], true) ? 'blocked' : 'error', 'results' => [], 'httpStatus' => $status,
            'error' => 'Wikipedia API returned HTTP ' . $status . ($retry_after !== '' ? '; Retry-After: ' . $retry_after : '') . '.'];
        return WikipediaResults::parse(json_decode($body, true), $limit) + ['httpStatus' => $status];
    }
}
