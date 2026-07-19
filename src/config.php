<?php

declare(strict_types=1);

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', '3306'),
    'database' => Env::get('DB_DATABASE', 'duskrail'),
    'username' => Env::get('DB_USERNAME', 'duskrail'),
    'password' => Env::get('DB_PASSWORD', 'change-me'),
    'siteURL' => Env::get('SITE_URL', 'http://duskrail.local'),
    'siteTitle' => Env::get('SITE_TITLE', 'DuskRail'),
    // Empty means autodetect (HeadlessBrowser tries chromium-browser,
    // google-chrome, chromium, google-chrome-stable on $PATH in that order).
    // Only needed if none of those names match what's installed.
    'chromeBinary' => Env::get('CHROME_BINARY', ''),
];
