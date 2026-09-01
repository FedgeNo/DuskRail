<?php

declare(strict_types=1);

/**
 * Installer / requirements checker: `php bin/install.php`.
 *
 * Checks the PHP environment, writes .env from the answers (or from
 * .env.example's defaults when run non-interactively), and makes sure the
 * configured database + user actually exist, creating them if a MariaDB/MySQL
 * root login is supplied. Safe to re-run - it only creates what's missing.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

// The same bootstrap every other entry point uses, rather than a second copy
// of it here - this script's schema deltas call into app classes (Host, URL,
// Setting), and those expect the constants and autoloader everything else
// runs with, not a lookalike that drifts from them.
require __DIR__ . '/../init.php';

function supports_color(): bool
{
    return function_exists('stream_isatty') && stream_isatty(STDOUT);
}

function color(string $text, string $code): string
{
    return supports_color() ? chr(27) . '[' . $code . 'm' . $text . chr(27) . '[0m' : $text;
}

function ok(string $message): void
{
    echo color('[ OK ]', '32') . ' ' . $message . '
';
}

function warn(string $message): void
{
    echo color('[WARN]', '33') . ' ' . $message . '
';
}

function fail_line(string $message): void
{
    echo color('[FAIL]', '31') . ' ' . $message . '
';
}

function fail(string $message): never
{
    fail_line($message);
    exit(1);
}

function heading(string $text): void
{
    echo '
' . color($text, '1') . '
';
}

function is_interactive(): bool
{
    return function_exists('stream_isatty') && stream_isatty(STDIN);
}

function prompt(string $question, string $default): string
{
    if (!is_interactive()) {
        return $default;
    }

    echo $question . ' [' . $default . ']: ';
    $answer = trim((string) fgets(STDIN));

    return $answer === '' ? $default : $answer;
}

/**
 * Reads a password without echoing it back to the terminal. `stty -echo` is
 * the only way to do this from plain PHP CLI (there's no readline hook for
 * it), and the trap restores the terminal even if this script dies mid-read -
 * a terminal left with echo off is a genuinely unpleasant thing to hand back
 * to someone.
 */
function prompt_secret(string $question): string
{
    if (!is_interactive()) {
        return '';
    }

    echo $question . ': ';
    shell_exec('stty -echo 2>/dev/null');
    $answer = trim((string) fgets(STDIN));
    shell_exec('stty echo 2>/dev/null');
    echo '
';

    return $answer;
}

/**
 * Sets KEY=value in an existing .env: fills in the line if the key is already
 * there (which is what a key present but blank looks like), appends it if it
 * isn't. Every other line is written back exactly as it was, comments and
 * ordering included - .env is hand-edited and holds live credentials, so
 * nothing this script doesn't understand should be reformatted or lost.
 *
 * Appending unconditionally would leave the file with the key twice, and
 * which of the two won would come down to which Env::load() happened to read
 * last.
 */
function set_env_line(string $path, string $key, string $value): void
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        fail('Couldn\'t read ' . $path . ' to set ' . $key . '.');
    }

    $replaced = false;

    foreach ($lines as $index => $line) {
        if (str_starts_with(ltrim($line), $key . '=')) {
            $lines[$index] = $key . '=' . $value;
            $replaced = true;
            break;
        }
    }

    if (!$replaced) {
        $lines[] = $key . '=' . $value;
    }

    file_put_contents($path, implode('
', $lines) . '
');
}

/**
 * MySQL/MariaDB never allows a placeholder (?) in place of an identifier
 * (a database/table/column/user name) - only for actual values - so a
 * database or username that has to be backtick-interpolated into DDL/DCL
 * (CREATE DATABASE, CREATE USER, GRANT, ...) can't go through a prepared
 * statement at all. This is the defense-in-depth substitute: refuse
 * anything that isn't a plain identifier before it ever reaches SQL.
 */
function validate_identifier(string $value, string $label): string
{
    if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $value) !== 1) {
        fail($label . ' "' . $value . '" may only contain letters, numbers, and underscores (max 64 chars).');
    }

    return $value;
}

// ---------- Requirements ----------

heading('Checking requirements');

if (version_compare(PHP_VERSION, '8.1', '<')) {
    fail('PHP 8.1+ is required, found ' . PHP_VERSION);
}
ok('PHP ' . PHP_VERSION);

foreach (['mysqli', 'gd', 'mbstring', 'curl', 'dom'] as $extension) {
    if (!extension_loaded($extension)) {
        fail('Missing required PHP extension: ' . $extension);
    }
    ok('ext-' . $extension . ' loaded');
}

// Soft requirement, not fail() - the crawler works fine without it, just
// falls back to marking a JS-challenge page crawled without ever resolving
// it (see HeadlessBrowser, docs/architecture.md's crawler conventions). CHROME_BINARY
// in .env overrides autodetection if none of these names match.
$chrome_found = false;
foreach (['chromium-browser', 'google-chrome', 'chromium', 'google-chrome-stable'] as $binary_name) {
    if (trim((string) shell_exec('command -v ' . escapeshellarg($binary_name) . ' 2>/dev/null')) !== '') {
        ok($binary_name . ' found (headless JS-challenge resolution available)');
        $chrome_found = true;
        break;
    }
}
if (!$chrome_found) {
    warn('No Chrome/Chromium binary found - JS-challenge pages will be left unresolved. Install one, or set CHROME_BINARY in .env if it\'s installed under a different name.');
}

// Soft requirement too - without it, PDFs are dropped from the crawl instead
// of having their text indexed (bin/crawler.php checks at runtime).
if (trim((string) shell_exec('command -v pdftotext 2>/dev/null')) !== '') {
    ok('pdftotext found (PDF text extraction available)');
} else {
    warn('pdftotext not found - PDFs won\'t be indexed. Install poppler-utils to enable that.');
}

// ---------- Directories ----------

heading('Checking directories');

// Everything the running crawler writes: thumbnails it serves, the TLD cache,
// and VAR_DIR's per-run state. These are the only directories the service user
// ever needs write access to - the checkout around them stays read-only to it.
foreach ([ROOT_DIR . '/thumbnails', ROOT_DIR . '/data', VAR_DIR] as $directory) {
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
        ok('Created ' . $directory);
    } else {
        ok($directory . ' already exists');
    }
}

// ---------- .env ----------

heading('Configuring .env');

$env_path = ROOT_DIR . '/.env';
$env_example_path = ROOT_DIR . '/.env.example';

if (is_file($env_path)) {
    ok('.env already exists, leaving it alone');
} else {
    // .env.example is the single source of truth for which keys .env needs
    // and their defaults - reading it here (rather than a separate hardcoded
    // list) means a key added to .env.example is automatically written to a
    // fresh .env too, instead of silently defaulting at read time
    // (Env::get()'s own fallback) without ever actually appearing in the
    // file a user would look at to configure it.
    $defaults = [];

    foreach (file($env_example_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $defaults[trim($key)] = trim($value);
    }

    // Only these are worth an interactive prompt - the rest (SITE_URL,
    // SITE_TITLE, ...) are fine taking .env.example's default straight
    // through, same as they always have via Env::get()'s fallback.
    $prompts = [
        'DB_HOST' => 'Database host',
        'DB_PORT' => 'Database port',
        'DB_DATABASE' => 'Database name',
        'DB_USERNAME' => 'Database username',
        'DB_PASSWORD' => 'Database password',
    ];

    $lines = [];
    foreach ($defaults as $key => $default) {
        // Never written from a prompt's plain answer - the file stores a hash,
        // and the password itself must not end up on disk anywhere.
        $value = $key === 'AUTH_PASSWORD_HASH'
            ? prompt_password_hash()
            : (isset($prompts[$key]) ? prompt($prompts[$key], $default) : $default);

        $lines[] = $key . '=' . $value;
    }

    file_put_contents($env_path, implode('
', $lines) . '
');
    ok('Wrote .env');
}

// An .env written before this key existed has no way to sign in at all, and
// Auth deliberately fails closed on a blank hash rather than letting everyone
// through - so an existing file missing it gets the same prompt, appended.
if (Env::get('AUTH_PASSWORD_HASH', '') === '') {
    $hash = prompt_password_hash();

    if ($hash === '') {
        warn('No login password set - nobody can sign in until AUTH_PASSWORD_HASH is filled in in .env.');
    } else {
        set_env_line($env_path, 'AUTH_PASSWORD_HASH', $hash);
        ok('Set AUTH_PASSWORD_HASH in .env');
    }
}

/**
 * Asks for the operator login password (twice, since it's never echoed and a
 * typo would otherwise lock the installer out of its own site) and returns
 * its hash.
 */
function prompt_password_hash(): string
{
    if (!is_interactive()) {
        return '';
    }

    while (true) {
        $password = prompt_secret('Login password for the search UI (blank to skip)');

        if ($password === '') {
            return '';
        }

        if ($password === prompt_secret('Repeat it')) {
            return password_hash($password, PASSWORD_DEFAULT);
        }

        fail_line('Those didn\'t match, try again.');
    }
}

// ---------- Database ----------

heading('Checking database');

$config = require ROOT_DIR . '/src/config.php';

// A database/user name gets backtick-interpolated into DDL below (MySQL
// never accepts a placeholder for an identifier) - validated up front so
// that interpolation is safe rather than trusting .env not to contain
// anything stranger than a plain name.
$database_name = validate_identifier($config['database'], 'DB_DATABASE');
$database_user = validate_identifier($config['username'], 'DB_USERNAME');

mysqli_report(MYSQLI_REPORT_OFF);

$connection = mysqli_connect(
    $config['host'],
    $config['username'],
    $config['password'],
    '',
    $config['port']
);

if ($connection !== false) {
    // SHOW ... LIKE ? refuses to prepare at all on MariaDB/MySQL (confirmed
    // directly - mysqli_prepare() returns false, "error near '?'") - real
    // escaping is the only option for this specific statement shape, unlike
    // every SELECT/INSERT/UPDATE elsewhere in this project.
    $exists = mysqli_query($connection, '
SHOW DATABASES LIKE \'' . mysqli_real_escape_string($connection, $database_name) . '\'
');

    if ($exists !== false && mysqli_num_rows($exists) > 0) {
        ok('Database "' . $database_name . '" exists and credentials work');
    } else {
        mysqli_query($connection, '
CREATE DATABASE `' . $database_name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
');
        ok('Created database "' . $database_name . '"');
    }

    mysqli_close($connection);
} else {
    warn('Could not connect as "' . $database_user . '" - the database/user may not exist yet.');

    $root_user = prompt('MariaDB root (or admin) username to create it', 'root');
    $root_pass = prompt('MariaDB root (or admin) password', '');

    $root_connection = mysqli_connect($config['host'], $root_user, $root_pass, '', $config['port']);

    if ($root_connection === false) {
        fail('Could not connect as "' . $root_user . '" either. Create the database/user manually and re-run this script.');
    }

    // CREATE USER/GRANT can't be prepared statements either (MySQL's
    // prepared-statement protocol doesn't support them at all, placeholders
    // or not) - mysqli_real_escape_string() on each value is the correct
    // substitute here, same as CREATE DATABASE/GRANT's backtick-quoted
    // identifier relies on validate_identifier() above instead of escaping.
    mysqli_query($root_connection, '
CREATE DATABASE IF NOT EXISTS `' . $database_name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
');

    mysqli_query($root_connection, '
CREATE USER IF NOT EXISTS \'' . mysqli_real_escape_string($root_connection, $database_user) . '\'@\'' . mysqli_real_escape_string($root_connection, $config['host']) . '\' IDENTIFIED BY \'' . mysqli_real_escape_string($root_connection, $config['password']) . '\'
');

    mysqli_query($root_connection, '
GRANT ALL PRIVILEGES ON `' . $database_name . '`.* TO \'' . mysqli_real_escape_string($root_connection, $database_user) . '\'@\'' . mysqli_real_escape_string($root_connection, $config['host']) . '\'
');

    mysqli_query($root_connection, '
FLUSH PRIVILEGES
');
    mysqli_close($root_connection);

    ok('Created database "' . $database_name . '" and user "' . $database_user . '"');
}

// mysqli_report() is a script-wide setting, not scoped to the $connection
// above - restored to PHP's own default here so the schema-delta code below
// (which calls into app classes like Host::findOrCreateByName() that assume
// mysqli throws on error, same as everywhere else in this project) gets its
// normal error handling back rather than silently swallowing failures for
// the rest of this run.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ---------- Schema ----------
//
// Deltas below run in the order they were actually made, oldest first. Each
// has a `check` (does the live database already reflect this delta?) and an
// `apply` (the SQL to run if not). `check` lets this stay idempotent and
// self-healing even if a delta was applied by hand outside this script.

// SHOW ... LIKE ? refuses to prepare at all on MariaDB/MySQL (confirmed
// directly - mysqli_prepare() returns false, "error near '?'"), unlike every
// SELECT/INSERT/UPDATE elsewhere in this project - real escaping via
// mysqli_real_escape_string() is the only option for this specific
// statement shape.

function table_exists(string $table): bool
{
    $connection = Database::connection();

    $result = mysqli_query($connection, '
SHOW TABLES
    LIKE \'' . mysqli_real_escape_string($connection, $table) . '\'
');

    return $result !== false && mysqli_num_rows($result) > 0;
}

function column_exists(string $table, string $column): bool
{
    // FROM `table` is a backtick-quoted identifier, not a value - it can't be
    // a placeholder either way, so it's validated instead (this function is
    // only ever called with the table names literally written in
    // schema_deltas() below, never anything from outside this script, but
    // validating costs nothing and keeps the same discipline as everywhere
    // else here).
    $table = validate_identifier($table, 'table name');
    $connection = Database::connection();

    $result = mysqli_query($connection, '
SHOW COLUMNS
    FROM `' . $table . '`
    LIKE \'' . mysqli_real_escape_string($connection, $column) . '\'
');

    return $result !== false && mysqli_num_rows($result) > 0;
}

/**
 * A column's declared type, lowercased ('mediumtext', 'varchar(255)'), or ''
 * if there's no such column - for deltas that change a column's type rather
 * than add one, where its mere existence says nothing about whether the
 * delta has been applied.
 */
function column_type(string $table, string $column): string
{
    $select = mysqli_prepare(Database::connection(), '
SELECT `COLUMN_TYPE`
    FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
        AND `TABLE_NAME` = ?
        AND `COLUMN_NAME` = ?
    LIMIT 1
');
    mysqli_stmt_bind_param($select, 'ss', $table, $column);
    mysqli_stmt_execute($select);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

    return $row !== null ? strtolower($row['COLUMN_TYPE']) : '';
}

/**
 * Unlike table_exists()/column_exists(), this is a real SELECT against
 * information_schema (not a SHOW ... LIKE), so it can be a normal prepared
 * statement with placeholders like everywhere else.
 */
function index_exists(string $table, string $indexName): bool
{
    $select = mysqli_prepare(Database::connection(), '
SELECT 1
    FROM `information_schema`.`STATISTICS`
    WHERE `TABLE_SCHEMA` = DATABASE()
        AND `TABLE_NAME` = ?
        AND `INDEX_NAME` = ?
    LIMIT 1
');
    mysqli_stmt_bind_param($select, 'ss', $table, $indexName);
    mysqli_stmt_execute($select);
    $result = mysqli_stmt_get_result($select);

    return $result !== false && mysqli_num_rows($result) > 0;
}

function run_sql(string $sql): void
{
    if (!mysqli_query(Database::connection(), $sql)) {
        fail('Schema delta failed: ' . mysqli_error(Database::connection()) . '
' . $sql);
    }
}

function schema_deltas(): array
{
    return [
        [
            'name' => 'create_items_and_links_tables',
            'check' => fn () => table_exists('Items') && table_exists('Links'),
            'apply' => function (): void {
                run_sql('
CREATE TABLE `Items` (
  `itemId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `keywords` varchar(255) DEFAULT NULL,
  `fullText` longtext DEFAULT NULL,
  `fullHTML` longtext DEFAULT NULL,
  PRIMARY KEY (`itemId`),
  FULLTEXT KEY `title_description_keywords_fullText` (`title`,`description`,`keywords`,`fullText`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                run_sql('
CREATE TABLE `Links` (
  `parentId` int(10) unsigned NOT NULL,
  `childId` int(10) unsigned NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`parentId`,`childId`),
  KEY `childId_parentId` (`childId`,`parentId`),
  CONSTRAINT `Links_ibfk_1` FOREIGN KEY (`parentId`) REFERENCES `Items` (`itemId`) ON DELETE CASCADE,
  CONSTRAINT `Links_ibfk_2` FOREIGN KEY (`childId`) REFERENCES `Items` (`itemId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            },
        ],
        [
            'name' => 'add_url_to_items',
            'check' => fn () => column_exists('Items', 'url'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    ADD COLUMN `url` varchar(767) NOT NULL AFTER `itemId`,
    ADD UNIQUE KEY `url` (`url`)
');
            },
        ],
        [
            'name' => 'add_crawledtime_to_items',
            'check' => fn () => column_exists('Items', 'crawledTime'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    ADD COLUMN `crawledTime` int(10) unsigned DEFAULT NULL
');
            },
        ],
        [
            'name' => 'add_inc_to_items',
            'check' => fn () => column_exists('Items', 'inc'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    ADD COLUMN `inc` int(10) unsigned NOT NULL DEFAULT 1
');
            },
        ],
        [
            'name' => 'add_fulltext_to_links_description',
            'check' => fn () => index_exists('Links', 'description'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Links`
    ADD FULLTEXT KEY `description` (`description`)
');
            },
        ],
        [
            'name' => 'create_hosts_table',
            'check' => fn () => table_exists('Hosts'),
            'apply' => function (): void {
                run_sql('
CREATE TABLE `Hosts` (
  `hostId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `host` varchar(255) NOT NULL,
  `robotsTxt` text DEFAULT NULL,
  `crawledTime` int(10) unsigned DEFAULT NULL,
  `nextCrawlTime` int(10) unsigned DEFAULT NULL,
  `inc` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`hostId`),
  UNIQUE KEY `host` (`host`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            },
        ],
        [
            // check requires the index too, not just the column - a run
            // that's interrupted partway through the backfill loop below
            // (this iterates every row in Items, one query pair per row;
            // on a large table that can take a while) would otherwise look
            // "already satisfied" on the next run from the column alone,
            // permanently skipping the rest of the backfill and the
            // NOT NULL/FK at the end.
            'name' => 'add_hostid_to_items',
            'check' => fn () => column_exists('Items', 'hostId') && index_exists('Items', 'hostId_crawledTime'),
            'apply' => function (): void {
                $connection = Database::connection();

                if (!column_exists('Items', 'hostId')) {
                    run_sql('
ALTER TABLE `Items`
    ADD COLUMN `hostId` int(10) unsigned DEFAULT NULL AFTER `url`
');
                }

                // Backfilled via our own URL parser rather than raw SQL
                // string-mangling - it's the exact same host-extraction
                // logic every other part of the app already trusts. Only
                // rows still missing it, so a resumed run picks up where an
                // interrupted one left off instead of redoing finished rows.
                $result = mysqli_query($connection, '
SELECT `itemId`, `url`
    FROM `Items`
    WHERE `hostId` IS NULL
');

                $update = mysqli_prepare($connection, '
UPDATE `Items`
    SET `hostId` = ?
    WHERE `itemId` = ?
');

                while ($row = mysqli_fetch_assoc($result)) {
                    $hostId = Host::findOrCreateByName((new URL($row['url'])) -> host) -> hostId;
                    mysqli_stmt_bind_param($update, 'ii', $hostId, $row['itemId']);
                    mysqli_stmt_execute($update);
                }

                if (!index_exists('Items', 'hostId_crawledTime')) {
                    run_sql('
ALTER TABLE `Items`
    MODIFY COLUMN `hostId` int(10) unsigned NOT NULL,
    ADD KEY `hostId_crawledTime` (`hostId`,`crawledTime`),
    ADD CONSTRAINT `Items_ibfk_1` FOREIGN KEY (`hostId`) REFERENCES `Hosts` (`hostId`)
');
                }
            },
        ],
        [
            // Lets Item::nextToCrawl() atomically reserve a row before
            // handing it to a crawler process, so running several crawlers
            // concurrently can't hand the same item to two of them at once.
            'name' => 'add_claimedUntil_to_items',
            'check' => fn () => column_exists('Items', 'claimedUntil'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    ADD COLUMN `claimedUntil` int(10) unsigned DEFAULT NULL AFTER `crawledTime`
');
            },
        ],
        [
            // Everything that reads the queue orders by crawledTime alone -
            // Item::nextToCrawl() to pick the next item, api/recent-items.php
            // to seed the live feed. hostId_crawledTime can't serve either
            // (its leading column is hostId), so both were scanning the whole
            // table and sorting it on every single call.
            'name' => 'add_crawledtime_index_to_items',
            'check' => fn () => index_exists('Items', 'crawledTime'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    ADD KEY `crawledTime` (`crawledTime`)
');
            },
        ],
        [
            // robotsTxt on its own can't say whether an empty value means "we
            // asked and the host has no rules" or "we couldn't reach it" -
            // and the second must never be read as permission to crawl. These
            // two record which it was and when, so a failure can be retried
            // rather than cached as a permanent all-clear (see Host).
            'name' => 'add_robotstxt_freshness_to_hosts',
            'check' => fn () => column_exists('Hosts', 'robotsTxtFetchedTime') && column_exists('Hosts', 'robotsTxtFetched'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Hosts`
    ADD COLUMN `robotsTxtFetched` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `robotsTxt`,
    ADD COLUMN `robotsTxtFetchedTime` int(10) unsigned DEFAULT NULL AFTER `robotsTxtFetched`
');

                // Every existing row predates the distinction, so none of
                // them can say which case its robotsTxt represents. Cleared
                // rather than guessed: robotsTxtFetchedTime NULL is exactly
                // "never fetched", so each host re-asks once and records a
                // real answer the first time it's crawled again.
                run_sql('
UPDATE `Hosts`
    SET `robotsTxt` = NULL
');
            },
        ],
        [
            // A URL the crawler already resolved as unusable (a 404, a
            // non-HTML body, a broken redirect) gets its Items row deleted -
            // which means the very next recrawl of any page linking to it
            // creates it again, fetches it again, and deletes it again,
            // forever. This remembers the verdict so it can be skipped at
            // discovery instead.
            'name' => 'create_deadurls_table',
            'check' => fn () => table_exists('DeadURLs'),
            'apply' => function (): void {
                run_sql('
CREATE TABLE `DeadURLs` (
  `deadURLId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(767) NOT NULL,
  `reason` varchar(50) NOT NULL,
  `deadTime` int(10) unsigned NOT NULL,
  PRIMARY KEY (`deadURLId`),
  UNIQUE KEY `url` (`url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            },
        ],
        [
            // TEXT holds 64 KiB, and real robots.txt files exceed that -
            // linkedin.com's does, and storing it threw "Data too long" and
            // killed the worker outright. MEDIUMTEXT comfortably covers the
            // 500 KiB Host reads at most (see MAX_ROBOTS_TXT_BYTES, which is
            // the limit Google documents for its own parser).
            'name' => 'widen_hosts_robotstxt',
            'check' => fn () => column_type('Hosts', 'robotsTxt') === 'mediumtext',
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Hosts`
    MODIFY COLUMN `robotsTxt` mediumtext DEFAULT NULL
');
            },
        ],
        [
            // Carries the focused-crawl topic between the web side and the
            // crawler. A file in the project directory would need the web
            // server to have write access inside the checkout purely to hand
            // a string to a CLI process, and that one requirement is what
            // drives the whole ACL/SELinux setup. Both sides already share
            // this database.
            'name' => 'create_settings_table',
            'check' => fn () => table_exists('Settings'),
            'apply' => function (): void {
                run_sql('
CREATE TABLE `Settings` (
  `settingId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`settingId`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

                // Carry over whatever topic the old file held, so a running
                // focused crawl isn't silently reset by this upgrade.
                $legacyTopicFile = ROOT_DIR . '/crawl-topic';

                if (is_file($legacyTopicFile)) {
                    Setting::store(CRAWL_TOPIC_SETTING, trim((string) file_get_contents($legacyTopicFile)));
                }
            },
        ],
        [
            // Request budgets for the public endpoints (see RateLimit). The
            // primary key is what makes counting a request a single atomic
            // upsert, and windowStart is indexed on its own so clearing out
            // finished windows doesn't scan the table.
            'name' => 'create_ratelimits_table',
            'check' => fn () => table_exists('RateLimits'),
            'apply' => function (): void {
                run_sql('
CREATE TABLE `RateLimits` (
  `bucket` varchar(16) NOT NULL,
  `identifier` varchar(64) NOT NULL,
  `windowStart` int(10) unsigned NOT NULL,
  `requests` int(10) unsigned NOT NULL,
  PRIMARY KEY (`bucket`,`identifier`,`windowStart`),
  KEY `windowStart` (`windowStart`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            },
        ],
        [
            // A page can opt out of being indexed (meta robots / X-Robots-Tag
            // noindex) while still being crawled for its links - so it keeps
            // its row and its crawledTime, and search just never returns it.
            'name' => 'add_noindex_to_items',
            'check' => fn () => column_exists('Items', 'noindex'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    ADD COLUMN `noindex` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `crawledTime`
');
            },
        ],
        [
            // Adaptive recrawl (see Item::markCrawled()): contentHash is what
            // the last crawl's text hashed to, recrawlAfterSeconds is how
            // long this item waits before being crawled again - doubled each
            // time the content comes back unchanged, reset when it differs.
            'name' => 'add_recrawl_columns_to_items',
            'check' => fn () => column_exists('Items', 'contentHash') && column_exists('Items', 'recrawlAfterSeconds'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    ADD COLUMN `contentHash` char(40) DEFAULT NULL AFTER `noindex`,
    ADD COLUMN `recrawlAfterSeconds` int(10) unsigned NOT NULL DEFAULT 604800 AFTER `contentHash`
');
            },
        ],
        [
            // crawlDelaySeconds carries a robots.txt Crawl-delay wish;
            // consecutiveFailures counts connection-level failures in a row,
            // driving an escalating host-wide backoff instead of item
            // deletion (a connection failure is about the host, not the URL).
            'name' => 'add_crawldelay_failures_to_hosts',
            'check' => fn () => column_exists('Hosts', 'crawlDelaySeconds') && column_exists('Hosts', 'consecutiveFailures'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Hosts`
    ADD COLUMN `crawlDelaySeconds` int(10) unsigned DEFAULT NULL AFTER `robotsTxtFetchedTime`,
    ADD COLUMN `consecutiveFailures` smallint(5) unsigned NOT NULL DEFAULT 0 AFTER `crawlDelaySeconds`
');
            },
        ],
        [
            // fullHTML is stored gzip-compressed from here on (see
            // Item::markCrawled()) - it's the whole raw page, kept only for
            // future re-processing, and compresses to about a quarter of its
            // size. LONGBLOB because compressed bytes aren't utf8mb4 text.
            // Rows written before this stay uncompressed and are told apart
            // by the gzip magic bytes when read.
            'name' => 'convert_fullhtml_to_longblob',
            'check' => fn () => column_type('Items', 'fullHTML') === 'longblob',
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    MODIFY COLUMN `fullHTML` longblob DEFAULT NULL
');
            },
        ],
        [
            // The crawledTime index gains type as a second column and the
            // single-column version goes: every reader either filters or
            // orders on crawledTime alone (still served identically by the
            // leading column) or, like the home page's page/image counts,
            // wants type for exactly the rows a crawledTime range selects -
            // which the composite answers from the index alone instead of
            // touching each crawled row.
            'name' => 'widen_items_crawledtime_index_with_type',
            'check' => fn () => index_exists('Items', 'crawledTime_type'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    DROP KEY `crawledTime`,
    ADD KEY `crawledTime_type` (`crawledTime`, `type`)
');
            },
        ],
        [
            // Every queue pick asks "which hosts are due" - unindexed, that
            // was a full Hosts scan per pick, invisible at a few thousand
            // hosts and a scan of the whole table once hosts number in the
            // hundreds of thousands. NULL (never crawled) and <= now both
            // sit at the front of this index, so the due set is one ordered
            // range read.
            'name' => 'add_nextcrawltime_index_to_hosts',
            'check' => fn () => index_exists('Hosts', 'nextCrawlTime'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Hosts`
    ADD KEY `nextCrawlTime` (`nextCrawlTime`)
');
            },
        ],
        [
            // "Is this item due for a recrawl" was crawledTime +
            // recrawlAfterSeconds <= now - arithmetic no index can serve, so
            // finding the next due recrawl meant walking crawled rows until
            // one passed, and walking every one of them when none did. The
            // stored generated column makes due-ness a plain indexed range:
            // NULL for uncrawled rows (excluded from any <= comparison for
            // free), maintained by the database whenever either operand
            // changes.
            'name' => 'add_recrawlduetime_to_items',
            'check' => fn () => column_exists('Items', 'recrawlDueTime'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    ADD COLUMN `recrawlDueTime` int(10) unsigned GENERATED ALWAYS AS (`crawledTime` + `recrawlAfterSeconds`) STORED AFTER `recrawlAfterSeconds`,
    ADD KEY `recrawlDueTime` (`recrawlDueTime`)
');
            },
        ],
        [
            // Search ranking counts how many distinct *domains* link to a
            // result. Counting hostnames instead lets one registered domain
            // with a wildcard DNS record mint them without limit -
            // a.evil.com, b.evil.com, ... - each looking like another
            // independent site endorsing whatever it points at. The
            // registrable domain
            // (PublicSuffixList) is the unit that costs money to add, so it's
            // the unit worth counting; stored per host because no SQL
            // expression can derive it (bbc.co.uk and theguardian.co.uk are
            // two owners, a.evil.com and b.evil.com are one, and no amount of
            // label counting tells them apart). Backfilled below, after the
            // list itself is fetched. No index: it's read through a primary
            // -key join and never looked up by.
            'name' => 'add_domain_to_hosts',
            'check' => fn () => column_exists('Hosts', 'domain'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Hosts`
    ADD COLUMN `domain` varchar(255) NOT NULL DEFAULT \'\' AFTER `host`
');
            },
        ],
        [
            // The meta keywords tag carried the same weight in relevance as
            // the page's actual text. It is the one indexed field a page
            // author writes purely for search engines: nobody reading the
            // page ever sees it, so nothing about it has to be true, which is
            // exactly why every major engine stopped counting it decades ago.
            // The column stays (it costs nothing and is still worth showing);
            // it just no longer votes on what a page is about.
            'name' => 'drop_keywords_from_items_fulltext',
            'check' => fn () => index_exists('Items', 'title_description_fullText'),
            'apply' => function (): void {
                run_sql('
ALTER TABLE `Items`
    DROP KEY `title_description_keywords_fullText`,
    ADD FULLTEXT KEY `title_description_fullText` (`title`, `description`, `fullText`)
');
            },
        ],
        [
            // Items.inc means "how many distinct pages link here". A row
            // carrying a count of every mention instead reflects one page
            // repeating a link and every recrawl of it - hundreds off a
            // single link edge from a single domain, which as a ranking
            // signal is one page voting for itself over and over. Recomputed
            // from the Links table, which holds the honest answer.
            //
            // No `check` that can pass: there's nothing in the schema to
            // detect, and re-running it is harmless because it derives the
            // value rather than adjusting it. The Migrations record is what
            // keeps it to once.
            'name' => 'recount_items_inc_from_links',
            'check' => fn () => false,
            'apply' => function (): void {
                run_sql('
UPDATE `Items`
    SET `inc` = (
        SELECT COUNT(*)
            FROM `Links`
            WHERE `Links`.`childId` = `Items`.`itemId`
    )
');
            },
        ],
    ];
}

heading('Checking schema');

$connection = Database::connection();

run_sql('
CREATE TABLE IF NOT EXISTS `Migrations` (
  `migrationId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `appliedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migrationId`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

foreach (schema_deltas() as $delta) {
    $name = $delta['name'];

    $recorded_stmt = mysqli_prepare($connection, '
SELECT 1
    FROM `Migrations`
    WHERE `name` = ?
');
    mysqli_stmt_bind_param($recorded_stmt, 's', $name);
    mysqli_stmt_execute($recorded_stmt);
    $already_recorded = mysqli_stmt_get_result($recorded_stmt);

    if ($already_recorded !== false && mysqli_num_rows($already_recorded) > 0) {
        continue;
    }

    if (($delta['check'])()) {
        ok('Delta "' . $name . '" already satisfied, recording it');
    } else {
        ($delta['apply'])();
        ok('Applied delta "' . $name . '"');
    }

    $insert_stmt = mysqli_prepare($connection, '
INSERT INTO `Migrations` (`name`)
    VALUES (?)
');
    mysqli_stmt_bind_param($insert_stmt, 's', $name);

    if (!mysqli_stmt_execute($insert_stmt)) {
        fail('Failed to record migration "' . $name . '": ' . mysqli_error($connection));
    }
}

// ---------- Published lists ----------
//
// Neither list is bundled: URL::isValid() refuses to crawl anything without a
// real TLD (TLDs), and search ranking can't tell one owner from another
// without the Public Suffix List. Both are fetched here rather than left to
// the weekly timer, so a freshly cloned install can crawl immediately instead
// of failing every URL closed until the timer's first run.

heading('Fetching published lists');

if (!TLDs::refresh() && !TLDs::isCached()) {
    fail('Couldn\'t fetch the IANA TLD list, and none is cached - nothing will be crawlable');
}

ok('TLD list ready');

if (!PublicSuffixList::refresh() && !PublicSuffixList::isCached()) {
    fail('Couldn\'t fetch the Public Suffix List, and none is cached - the crawler can\'t record host domains');
}

ok('Public Suffix List ready');

// ---------- Host domains ----------
//
// Hosts.domain is derived from the list just fetched, so it's filled in here
// rather than in the schema delta that added the column - no SQL expression
// can compute it. Only rows that don't have one yet are touched, which makes
// this both the backfill for an existing install and a no-op on every run
// after the first.

heading('Filling in host domains');

$hosts_needing_domain = mysqli_query($connection, '
SELECT `hostId`, `host`
    FROM `Hosts`
    WHERE `domain` = \'\'
');

$set_domain = mysqli_prepare($connection, '
UPDATE `Hosts`
    SET `domain` = ?
    WHERE `hostId` = ?
');
$filled = 0;

// One transaction around the lot. Committed row by row, each UPDATE is its
// own flush to disk, which measured at roughly seventy rows a second - fine
// for the few thousand hosts an early install has, hours for the millions
// this index is built to reach.
mysqli_begin_transaction($connection);

while ($row = mysqli_fetch_assoc($hosts_needing_domain)) {
    $domain = PublicSuffixList::registrableDomain($row['host']);
    $hostId = (int) $row['hostId'];
    mysqli_stmt_bind_param($set_domain, 'si', $domain, $hostId);
    mysqli_stmt_execute($set_domain);
    $filled++;
}

mysqli_commit($connection);

ok($filled === 0 ? 'Every host already has its domain' : 'Filled in ' . $filled . ' host domain(s)');


// ---------- Manual setup ----------
//
// Creating a user, writing a vhost, labelling files for SELinux and
// installing a systemd unit all need root, and this script intentionally
// never asks for sudo. They're printed instead - with this install's real
// paths filled in, so they can be pasted rather than hand-edited.
//
// Nothing here is specific to the machine this was developed on: the project
// path, the PHP binary, the web server's user and the number of worker slots
// are all read from the environment this is running in.

/**
 * The account the web server runs as - "apache" on Fedora/RHEL, "www-data" on
 * Debian/Ubuntu, something else again elsewhere. Detected rather than assumed,
 * since guessing wrong produces setup commands that fail on half the distros
 * this could be installed on.
 */
function web_server_user(): string
{
    $running = trim((string) shell_exec('ps -eo user,comm 2>/dev/null | grep -E \'httpd|apache2|nginx\' | grep -v root | head -1 | cut -d\' \' -f1'));

    if ($running !== '') {
        return $running;
    }

    foreach (['apache', 'www-data', 'nginx', 'http'] as $candidate) {
        if (user_exists($candidate)) {
            return $candidate;
        }
    }

    return 'apache';
}

$service_user = 'duskrail';
$web_user = web_server_user();

// Whoever owns the checkout - they edit .env and run the CLI scripts by hand,
// so the permissions below have to keep working for them as well as for the
// two service accounts. Read from the filesystem rather than assumed to be
// whoever happens to be running this installer.
$owner = file_owner_name(ROOT_DIR) ?? get_current_user();
$php_binary = PHP_BINARY;
$root = ROOT_DIR;
$var_dir = VAR_DIR;
$site_host = parse_url($config['siteURL'], PHP_URL_HOST) ?: 'duskrail.localhost';

// The web server only ever reads the checkout, so it needs traverse (x) on
// every directory above it. Listed explicitly because a checkout under a home
// directory is the common case and home directories are not world-traversable.
$traversal_paths = [];
$path = dirname($root);

while ($path !== '/' && $path !== '' && $path !== '.') {
    $traversal_paths[] = $path;
    $path = dirname($path);
}

$traversal = implode(' ', array_reverse($traversal_paths));

heading('Service account and permissions (run manually, needs sudo)');
echo <<<SHELL
# The crawler runs as its own system account rather than as the web server's.
# It is a daemon that drives a browser and writes to disk for hours at a time;
# the web server's account is shared by every other site on the machine, and
# neither should be able to reach the other's files just by existing.
sudo useradd --system --no-create-home --home-dir /var/lib/{$service_user} --shell /sbin/nologin {$service_user}

# The three directories the crawler writes: thumbnails (served by the web
# server, so it reads them too), the cached published lists (written by the
# refresh timer, read by both services), and its own run state. The
# checkout itself stays owned by whoever cloned it and is never writable by
# either service - nothing in it needs to be. The default ACL (d:) is what
# makes files the crawler creates later inherit the same access, rather than
# only the directories that exist right now having it.
sudo chown -R {$service_user}: {$root}/thumbnails {$root}/data {$var_dir}
sudo chmod 755 {$root}/thumbnails {$root}/data {$var_dir}
sudo setfacl -R -m u:{$owner}:rwX -m d:u:{$owner}:rwX {$root}/thumbnails {$root}/data {$var_dir}

# .env holds the database credentials and the login hash. Owned by the person
# who edits it, read by the two services that need it, and by nobody else -
# note read only, since neither service ever writes it.
sudo chown {$owner}: {$root}/.env
sudo chmod 600 {$root}/.env
sudo setfacl -m u:{$service_user}:r -m u:{$web_user}:r {$root}/.env

# The web server reads the checkout and needs to traverse into it.
sudo setfacl -m u:{$web_user}:x {$traversal}
sudo setfacl -m u:{$service_user}:x {$traversal}

SHELL;

if (trim((string) shell_exec('command -v getenforce 2>/dev/null')) !== '') {
    echo <<<SHELL
# SELinux: the checkout is web content, and the two directories the web server
# reads or writes are labelled accordingly. Registered with semanage, not just
# applied with chcon, so the labels survive a relabel.
sudo semanage fcontext -a -t httpd_sys_content_t "{$root}(/.*)?"
sudo semanage fcontext -a -t httpd_sys_rw_content_t "{$root}/thumbnails(/.*)?"
sudo restorecon -Rv {$root}

SHELL;

    if (str_starts_with($root, '/home/')) {
        echo <<<SHELL
# The checkout lives under a home directory, which the web server is not
# allowed to read from by default.
sudo setsebool -P httpd_enable_homedirs on

SHELL;
    }
}

// ---------- Web server ----------

heading('Web server vhost (run manually, needs sudo)');
echo <<<SHELL
sudo tee /etc/httpd/conf.d/duskrail.conf > /dev/null <<'EOF'
# Server-wide, deliberately outside the vhosts: the default Server header
# names the exact Apache, OpenSSL and distribution build, which is a list of
# which published CVEs to try. "Prod" reduces it to "Apache".
ServerTokens Prod
ServerSignature Off

# Plain HTTP exists only to send everyone to HTTPS - the session and
# rate-limit cookies are Secure-only, so a page served over HTTP wouldn't
# work properly anyway.
<VirtualHost *:80>
    ServerName {$site_host}
    Redirect permanent / https://{$site_host}/
</VirtualHost>

<VirtualHost *:443>
    ServerName {$site_host}
    DocumentRoot {$root}

    # Nothing in the checkout is reachable unless it is named below.
    #
    # An allowlist, never a list of what to hide: such a list can only name
    # what existed when it was written, leaving everything added afterwards
    # public by default. That reaches as far as the .git directory - HEAD, the
    # pack index and the packs themselves are all fetchable, and the complete
    # source and its full history come back out with an off-the-shelf script.
    # An allowlist cannot fail that way, because a file nobody has thought
    # about is not served.
    <Directory {$root}>
        # .htaccess is not used, and honouring it means every request walks
        # the directory tree looking for one it will not find.
        AllowOverride None
        # -Indexes so a directory with nothing to serve says so rather than
        # listing itself.
        Options -Indexes -ExecCGI +FollowSymLinks
        DirectoryIndex index.php

        # Granted at directory level purely so a request for "/" gets as far
        # as DirectoryIndex; the deny below is what decides things, and it is
        # per file.
        Require all granted

        # Every file in the checkout, at any depth, unless named below.
        <FilesMatch "^.*\$">
            Require all denied
        </FilesMatch>

        # The pages a visitor can actually be on.
        <FilesMatch "^(index|login|logout|watch)\.php\$">
            Require all granted
        </FilesMatch>

        # Their assets. Named individually rather than by extension, so a
        # stylesheet or script that lands anywhere else in the checkout does
        # not become public by virtue of its suffix.
        <FilesMatch "^(search\.js|watch\.js|style\.css|bootstrap\.min\.css|favicon\.svg)\$">
            Require all granted
        </FilesMatch>
    </Directory>

    # Directories that hold no URLs at all, denied outright as well - so a
    # file that one day shares a name with something public above (a src/
    # index.php, say) still isn't served.
    <DirectoryMatch "{$root}/(var|bin|src|tests|data|logs|backups)(/|\$)">
        Require all denied
    </DirectoryMatch>

    # The JSON endpoints. Each one gates itself (public ones rate-limit,
    # operator ones require a session); this only grants reaching them.
    <Directory {$root}/api>
        <FilesMatch "\.php\$">
            Require all granted
        </FilesMatch>
    </Directory>

    # Crawled image thumbnails, written by the crawler and read by the web
    # server. Only the .jpg files it actually writes.
    <Directory {$root}/thumbnails>
        <FilesMatch "\.jpg\$">
            Require all granted
        </FilesMatch>
    </Directory>

    # Belt and braces over the deny-by-default above: no dot-directory or
    # dot-file at any depth, whatever else may grant it. .git is the one that
    # matters, .env the one that would matter most.
    <DirectoryMatch "/\.">
        Require all denied
    </DirectoryMatch>

    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    # The catch-all deny is matched against a request's last path component,
    # and for "/" that component is empty - so without this the home page is
    # refused before DirectoryIndex gets to name index.php. Location sections
    # are merged after Files ones, and this matches the bare root URL and
    # nothing else.
    <LocationMatch "^/\$">
        Require all granted
    </LocationMatch>

    SSLEngine on
    SSLCertificateFile /etc/pki/tls/certs/duskrail-mkcert.pem
    SSLCertificateKeyFile /etc/pki/tls/private/duskrail-mkcert-key.pem

    Header always set Strict-Transport-Security "max-age=31536000"

    # PHP announces its exact version in every response otherwise. Unset here
    # rather than via expose_php in php.ini, which is shared with every other
    # site on the machine.
    Header always unset X-Powered-By

    ErrorLog /var/log/httpd/duskrail-ssl-error.log
    CustomLog /var/log/httpd/duskrail-ssl-access.log combined
</VirtualHost>
EOF

SHELL;

// A name under .localhost resolves to this machine in every modern browser
// (they hardwire it, per RFC 6761) and, on systemd-resolved systems, for
// every program - no hosts entry needed or printed. Anything else does need
// one, since it isn't a name any DNS server will answer for.
if (!str_ends_with($site_host, '.localhost') && $site_host !== 'localhost') {
    echo <<<SHELL
echo "127.0.0.1 {$site_host}" | sudo tee -a /etc/hosts > /dev/null

SHELL;
}

echo <<<SHELL
# A locally-trusted certificate for this site, in its own file. Never reuse
# or regenerate a certificate file another vhost points at (e.g. the stock
# ssl.conf's localhost-mkcert.pem): regenerating a shared file with only this
# site's names silently breaks TLS for every other name that was on it.
mkcert -cert-file /tmp/duskrail-mkcert.pem -key-file /tmp/duskrail-mkcert-key.pem {$site_host}
sudo cp /tmp/duskrail-mkcert.pem /etc/pki/tls/certs/duskrail-mkcert.pem
sudo cp /tmp/duskrail-mkcert-key.pem /etc/pki/tls/private/duskrail-mkcert-key.pem
sudo chown root:root /etc/pki/tls/certs/duskrail-mkcert.pem /etc/pki/tls/private/duskrail-mkcert-key.pem
sudo chmod 644 /etc/pki/tls/certs/duskrail-mkcert.pem
sudo chmod 600 /etc/pki/tls/private/duskrail-mkcert-key.pem
rm /tmp/duskrail-mkcert.pem /tmp/duskrail-mkcert-key.pem

sudo apachectl configtest && sudo systemctl reload httpd

SHELL;

// ---------- Crawler service ----------

heading('Crawler service (run manually, needs sudo)');
echo <<<SHELL
sudo tee /etc/systemd/system/duskrail-crawler.service > /dev/null <<'EOF'
[Unit]
Description=DuskRail crawler manager (supervises bin/crawler.php workers)
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=simple
User={$service_user}
Group={$service_user}
WorkingDirectory={$root}
ExecStart={$php_binary} {$root}/bin/crawler-manager.php
# SIGTERM to the manager alone (not the whole cgroup) so it can drain workers
# gracefully - it stops spawning and exits once every in-flight worker has
# finished its current item (crawler.php ignores SIGTERM). Anything still
# alive after TimeoutStopSec is SIGKILLed as a backstop.
KillMode=mixed
TimeoutStopSec=120
Restart=no
# Chrome refuses to start at all without a writable HOME - its crash handler
# is initialised before anything else and has no fallback. systemd creates and
# owns this directory for the service account.
StateDirectory={$service_user}
Environment=HOME=%S/{$service_user}
# Each browser profile goes in a temp directory of its own; this keeps them
# out of the shared /tmp and takes them with the service when it stops.
PrivateTmp=true
# systemd hands services a 1024 soft file-descriptor limit and expects a
# daemon needing more to raise it itself. Chrome does not, and a browser
# driving several tabs over TLS exhausts 1024 easily - at which point requests
# start failing, which the crawler can only read as "this URL is unreachable"
# and deletes good items over. An interactive shell sits at 524288 already,
# so running the manager by hand never shows this.
LimitNOFILE=524288

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload

# Left disabled (no boot-start) and stopped on purpose - drive it by hand:
#   sudo systemctl start duskrail-crawler
#   sudo systemctl stop duskrail-crawler     # graceful: workers finish first

SHELL;

// ---------- List refresh service ----------

heading('Weekly list refresh (run manually, needs sudo)');
echo <<<SHELL
# The two published lists this project depends on but doesn't own: IANA's
# root-zone TLD list and Mozilla's Public Suffix List. This runs as its own
# scheduled job rather than lazily inside the crawler, so that a list expiring
# is never something a crawl has to stop and deal with mid-run.
sudo tee /etc/systemd/system/duskrail-refresh-lists.service > /dev/null <<'EOF'
[Unit]
Description=DuskRail published-list refresh (IANA TLDs, Public Suffix List)
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User={$service_user}
Group={$service_user}
WorkingDirectory={$root}
ExecStart={$php_binary} {$root}/bin/refresh-lists.php
EOF

sudo tee /etc/systemd/system/duskrail-refresh-lists.timer > /dev/null <<'EOF'
[Unit]
Description=Refresh DuskRail's published lists weekly

[Timer]
OnCalendar=weekly
# Run on the next boot if the machine was off when this was due - both lists
# only matter for being current, so a skipped week is worth catching up on.
Persistent=true
# Neither publisher needs every installation in the world arriving at midnight
# on the same Sunday.
RandomizedDelaySec=1h

[Install]
WantedBy=timers.target
EOF
sudo systemctl daemon-reload

# Enabled and started, unlike the crawler: this one is maintenance that should
# just happen, and it costs two small downloads a week.
sudo systemctl enable --now duskrail-refresh-lists.timer

SHELL;

heading('Done');
echo 'DuskRail is set up.
';
