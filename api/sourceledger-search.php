<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';
$config = require SRC_DIR . '/config.php';
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$provided_token = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
if ($config['sourceLedgerToken'] === '' || $provided_token === '' || !hash_equals($config['sourceLedgerToken'], $provided_token)) {
    SearchJSONResponse::send(['error' => 'Authentication required.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    SearchJSONResponse::send(['error' => 'POST required.'], 405);
}
$query = $_POST['query'] ?? null;
$limit = filter_var($_POST['limit'] ?? 10, FILTER_VALIDATE_INT);
if (!is_string($query) || $limit === false) SearchJSONResponse::send(['error' => 'Supply a query string and integer limit.'], 400);
try {
    $adapter = match ($_POST['engine'] ?? 'marginalia') {
        'marginalia' => MarginaliaSearch::class,
        'newsnow' => NewsNowSearch::class,
        'wikipedia' => WikipediaSearch::class,
        default => throw new InvalidArgumentException('Unsupported search engine. Use marginalia, newsnow or wikipedia.'),
    };
    $result = $adapter::search($query, $limit);
    SearchJSONResponse::send($result, in_array($result['status'], ['ok', 'no_results'], true) ? 200 : 503);
} catch (InvalidArgumentException $exception) {
    SearchJSONResponse::send(['error' => $exception -> getMessage()], 400);
}
