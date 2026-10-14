<?php

declare(strict_types=1);

/** First-page browser search using the crawler's existing isolated Chrome tabs. */
abstract class BrowserSearch {
    abstract protected static function searchURL(string $query): string;

    protected const EMPTY_RESULTS_HTTP_STATUS = null;

    private const TIMEOUT_SECONDS = 30;
    private const MAX_HTML_BYTES = 2097152;

    public static function isEngineURL(string $value): bool {
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) return false;
        $host = strtolower($parts['host'] ?? '');
        return $host === static::HOST || str_ends_with($host, '.' . static::HOST);
    }

    public static function search(string $query, int $limit = 10): array {
        $query = trim($query);
        if ($query === '' || !mb_check_encoding($query, 'UTF-8') || mb_strlen($query) > 200
            || preg_match('/[\x00-\x1f\x7f]/', $query) || $limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Supply a query of 1–200 characters and a limit of 1–20 results.');
        }
        try {
            return SearchThrottle::run(VAR_DIR . '/search-throttle', static fn(): array => static::performSearch($query, $limit));
        } catch (RuntimeException $exception) {
            return ['engine' => static::ENGINE, 'query' => $query, 'status' => 'unavailable', 'results' => [], 'error' => $exception -> getMessage()];
        }
    }

    private static function performSearch(string $query, int $limit): array {
        $started = microtime(true);
        $metadata = ['engine' => static::ENGINE, 'query' => $query, 'requestedAt' => gmdate('c'), 'limit' => $limit];
        $endpoint = is_readable(CHROME_DEVTOOLS_ENDPOINT_FILE) ? trim(file_get_contents(CHROME_DEVTOOLS_ENDPOINT_FILE)) : '';
        if ($endpoint === '') return $metadata + ['status' => 'unavailable', 'results' => [], 'error' => 'The shared crawler browser endpoint file is missing, unreadable or empty. Check that the DuskRail crawler service is running.'];
        try {
            $connection = Database::connection();
            $lock = (int) mysqli_fetch_row(mysqli_query($connection, static::LOCK_SQL))[0];
        } catch (Throwable $exception) {
            error_log(static::LABEL . ' search lock failed: ' . $exception -> getMessage());
            return $metadata + ['status' => 'unavailable', 'results' => [],
                'error' => 'The database could not acquire the ' . static::LABEL . ' concurrency lock (error code ' . $exception -> getCode() . '). Details are in the DuskRail error log.'];
        }
        if ($lock !== 1) {
            return $metadata + ['status' => 'busy', 'results' => [], 'error' => 'A ' . static::LABEL . ' search is already running.'];
        }
        $tab = null;
        $stage = 'DNS validation';
        try {
            if (!IPAddress::hostResolvesPublicly(static::HOST)) throw new RuntimeException(static::LABEL . ' did not resolve exclusively to public addresses.');
            $stage = 'browser connection';
            $tab = new ChromeTab($endpoint, 3.0);
            $stage = 'page retrieval';
            $result = self::render($tab, static::searchURL($query), $limit);
        } catch (Throwable $exception) {
            error_log(static::LABEL . ' search failed: ' . $exception -> getMessage());
            $result = ['status' => 'error', 'results' => [], 'stage' => $stage,
                'error' => static::LABEL . ' ' . $stage . ' failed: ' . $exception -> getMessage()];
        } finally {
            try {
                $tab ?-> close();
            } catch (Throwable $exception) {
                error_log(static::LABEL . ' browser cleanup failed: ' . $exception -> getMessage());
            } finally {
                try {
                    mysqli_query($connection, static::UNLOCK_SQL);
                } catch (Throwable $exception) {
                    error_log(static::LABEL . ' search lock release failed: ' . $exception -> getMessage());
                }
            }
        }
        return $metadata + $result + ['durationMs' => (int) round((microtime(true) - $started) * 1000)];
    }

    private static function render(ChromeTab $tab, string $url, int $limit): array {
        $tab -> sendCommand('Network.enable');
        $tab -> sendCommand('Network.setBypassServiceWorker', ['bypass' => true]);
        $tab -> sendCommand('Fetch.enable', ['patterns' => [['urlPattern' => '*', 'requestStage' => 'Request']]]);
        $tab -> sendCommand('Page.enable');
        $navigation = $tab -> sendCommand('Page.navigate', ['url' => $url]);
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $evaluation = null;
        $next_read = microtime(true) + 0.5;
        $last_result = null;
        $status = null;
        while (microtime(true) < $deadline) {
            if ($evaluation === null && microtime(true) >= $next_read) {
                $evaluation = $tab -> sendCommand('Runtime.evaluate', [
                    'expression' => 'document.readyState !== "loading" && document.documentElement ? document.documentElement.outerHTML.slice(0, 2097153) : ""',
                    'returnByValue' => true,
                ]);
            }
            $message = $tab -> receiveMessage(min($deadline, microtime(true) + 0.5));
            if ($message === null) continue;
            if (($message['method'] ?? '') === 'Fetch.requestPaused') {
                $params = $message['params'];
                $target = $params['request']['url'] ?? '';
                $allowed = static::isEngineURL($target)
                    && !in_array($params['resourceType'] ?? '', ['Image', 'Media', 'Font'], true)
                    && IPAddress::hostResolvesPublicly((string) parse_url($target, PHP_URL_HOST));
                $tab -> sendCommand($allowed ? 'Fetch.continueRequest' : 'Fetch.failRequest',
                    $allowed ? ['requestId' => $params['requestId']] : ['requestId' => $params['requestId'], 'errorReason' => 'BlockedByClient']);
            }
            if (($message['method'] ?? '') === 'Network.responseReceived' && ($message['params']['type'] ?? '') === 'Document') {
                $status = (int) $message['params']['response']['status'];
                if ($status >= 400 && $status !== static::EMPTY_RESULTS_HTTP_STATUS) return ['status' => in_array($status, [403, 429], true) ? 'blocked' : 'error', 'results' => [], 'error' => static::LABEL . ' returned HTTP ' . $status . '.', 'httpStatus' => $status];
            }
            if (($message['method'] ?? '') === 'Network.loadingFailed' && ($message['params']['type'] ?? '') === 'Document') {
                return ['status' => 'error', 'results' => [], 'error' => static::LABEL . ' document request failed: '
                    . ($message['params']['errorText'] ?? 'No network error message supplied.')];
            }
            if (isset($message['error'])) return ['status' => 'error', 'results' => [],
                'error' => 'Chrome DevTools error ' . ($message['error']['code'] ?? '') . ': ' . ($message['error']['message'] ?? 'No error message supplied.')];
            if (($message['id'] ?? null) === $navigation && isset($message['result']['errorText'])) {
                return ['status' => 'error', 'results' => [], 'error' => static::LABEL . ' navigation failed: ' . $message['result']['errorText']];
            }
            if ($evaluation !== null && ($message['id'] ?? null) === $evaluation) {
                $evaluation = null;
                $next_read = microtime(true) + 0.5;
                if (isset($message['result']['exceptionDetails'])) return ['status' => 'error', 'results' => [],
                    'error' => 'Chrome could not read the ' . static::LABEL . ' page: ' . ($message['result']['exceptionDetails']['text'] ?? 'JavaScript evaluation failed.')];
                $html = $message['result']['result']['value'] ?? '';
                if (!is_string($html) || $html === '') continue;
                if (strlen($html) > self::MAX_HTML_BYTES) return ['status' => 'error', 'results' => [], 'error' => static::LABEL . ' rendered page exceeded the 2 MiB capture limit.'];
                $parsed = (static::PARSER)::parse($html, $limit);
                if ($status >= 400 && $parsed['status'] !== 'no_results') return ['status' => 'error', 'results' => [],
                    'error' => static::LABEL . ' returned HTTP ' . $status . '.', 'httpStatus' => $status];
                if ($parsed['status'] === 'blocked' || $parsed['status'] === 'no_results'
                    || ($parsed['status'] === 'ok' && $last_result === $parsed)) return $parsed + ['httpStatus' => $status];
                $last_result = $parsed;
            }
        }
        return ['status' => 'timeout', 'results' => [], 'httpStatus' => $status,
            'error' => static::LABEL . ' did not provide a recognized, settled result page within 30 seconds. '
                . ($last_result['error'] ?? ($last_result === null ? 'No readable page HTML was received.' : 'The result list was still changing.'))];
    }
}
