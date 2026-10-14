<?php

declare(strict_types=1);

final class SearchJSONResponse {
    public static function send(array $payload, int $status = 200): never {
        if (PHP_SAPI !== 'cli') {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($status >= 400 && PHP_SAPI === 'cli' ? 1 : 0);
    }
}
