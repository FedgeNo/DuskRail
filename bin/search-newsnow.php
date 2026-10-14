<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../init.php';
try {
    $limit = filter_var($argv[2] ?? 10, FILTER_VALIDATE_INT);
    if ($limit === false) throw new InvalidArgumentException('The result limit must be an integer.');
    $result = NewsNowSearch::search($argv[1] ?? '', $limit);
    SearchJSONResponse::send($result, in_array($result['status'], ['ok', 'no_results'], true) ? 200 : 503);
} catch (InvalidArgumentException $exception) {
    SearchJSONResponse::send(['error' => $exception -> getMessage()], 400);
}
