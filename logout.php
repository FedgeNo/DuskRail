<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method not allowed.';
    exit;
}

Auth::requireWritePage();
Auth::logOut();

header('Location: ' . ServerURL::absolute('/login.php'));
