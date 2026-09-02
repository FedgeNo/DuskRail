<?php

declare(strict_types=1);

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', '3306'),
    'database' => Env::get('DB_DATABASE', 'duskrail'),
    'username' => Env::get('DB_USERNAME', 'duskrail'),
    'password' => Env::get('DB_PASSWORD', ''),
    'siteURL' => Env::get('SITE_URL', 'https://duskrail.localhost'),
    'siteTitle' => Env::get('SITE_TITLE', 'DuskRail'),
    // password_hash() output for the single operator login (see Auth) - what
    // the crawl controls sit behind, not search. Deliberately no default: an
    // empty hash means "nobody can sign in", which is the only safe way for an
    // install that never configured one to fail.
    'authPasswordHash' => Env::get('AUTH_PASSWORD_HASH', ''),
    // How many bin/crawler.php workers bin/crawler-manager.php runs at once.
    // Configuration rather than a constant in the manager because the
    // installer has to know it too - each slot needs its own writable
    // crawler-current-item-N file, and a number only the manager knew would
    // leave the installer setting up the wrong count of them.
    'workerCount' => max(1, (int) Env::get('WORKER_COUNT', '3')),
    // Empty means autodetect (HeadlessBrowser tries chromium-browser,
    // google-chrome, chromium, google-chrome-stable on $PATH in that order).
    // Only needed if none of those names match what's installed.
    'chromeBinary' => Env::get('CHROME_BINARY', ''),
];
