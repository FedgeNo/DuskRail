<?php

declare(strict_types=1);

return [
    'host' => Env::get('DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('DB_PORT', '3306'),
    'database' => Env::get('DB_DATABASE', 'duskrail'),
    'username' => Env::get('DB_USERNAME', 'duskrail'),
    'password' => Env::get('DB_PASSWORD', ''),
    'manticoreHost' => Env::get('MANTICORE_HOST', '127.0.0.1'),
    'manticorePort' => (int) Env::get('MANTICORE_PORT', '9306'),
    'manticoreUsername' => Env::get('MANTICORE_USERNAME', ''),
    'manticorePassword' => Env::get('MANTICORE_PASSWORD', ''),
    'siteURL' => Env::get('SITE_URL', 'https://duskrail.localhost'),
    'siteTitle' => Env::get('SITE_TITLE', 'DuskRail'),
    'thumbnailDirectory' => Env::get('THUMBNAIL_DIRECTORY', ROOT_DIR . '/thumbnails'),
    'thumbnailMinimumFreeBytes' => Env::get('THUMBNAIL_MINIMUM_FREE_BYTES', '1.5T'),
    // password_hash() output for the single operator login (see Auth) - what
    // the crawl controls sit behind, not search. Deliberately no default: an
    // empty hash means "nobody can sign in", which is the only safe way for an
    // install that never configured one to fail.
    'authPasswordHash' => Env::get('AUTH_PASSWORD_HASH', ''),
    // Shared server-to-server bearer token for the Retrieval API (web
    // search and interactive page retrieval). Empty disables the API.
    'retrievalAPIToken' => Env::get('RETRIEVAL_API_TOKEN', ''),
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
    // Passes --disable-dev-shm-usage, moving Chrome's shared memory from
    // /dev/shm to files in the temp directory. Only for a host whose /dev/shm
    // is too small (containers): elsewhere it turns renderer traffic into
    // continuous disk writes that stall everything sharing that disk.
    'chromeDisableDevShmUsage' => Env::get('CHROME_DISABLE_DEV_SHM_USAGE', '0') === '1',
];
