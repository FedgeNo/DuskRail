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

define('ROOT_DIR', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $file = ROOT_DIR . '/src/classes/' . $class . '.php';

    if (is_file($file)) {
        require $file;
    }
});

function supports_color(): bool
{
    return function_exists('stream_isatty') && stream_isatty(STDOUT);
}

function color(string $text, string $code): string
{
    return supports_color() ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
}

function ok(string $message): void
{
    echo color('[ OK ]', '32') . ' ' . $message . "\n";
}

function warn(string $message): void
{
    echo color('[WARN]', '33') . ' ' . $message . "\n";
}

function fail_line(string $message): void
{
    echo color('[FAIL]', '31') . ' ' . $message . "\n";
}

function fail(string $message): never
{
    fail_line($message);
    exit(1);
}

function heading(string $text): void
{
    echo "\n" . color($text, '1') . "\n";
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

// ---------- Directories ----------

heading('Checking directories');

$thumbnails_dir = ROOT_DIR . '/thumbnails';

if (!is_dir($thumbnails_dir)) {
    mkdir($thumbnails_dir, 0755, true);
    ok('Created ' . $thumbnails_dir);
} else {
    ok($thumbnails_dir . ' already exists');
}

// ---------- .env ----------

heading('Configuring .env');

$env_path = ROOT_DIR . '/.env';
$env_example_path = ROOT_DIR . '/.env.example';

if (is_file($env_path)) {
    ok('.env already exists, leaving it alone');
} else {
    $defaults = [
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'duskrail',
        'DB_USERNAME' => 'duskrail',
        'DB_PASSWORD' => 'change-me',
    ];

    $answers = [
        'DB_HOST' => prompt('Database host', $defaults['DB_HOST']),
        'DB_PORT' => prompt('Database port', $defaults['DB_PORT']),
        'DB_DATABASE' => prompt('Database name', $defaults['DB_DATABASE']),
        'DB_USERNAME' => prompt('Database username', $defaults['DB_USERNAME']),
        'DB_PASSWORD' => prompt('Database password', $defaults['DB_PASSWORD']),
    ];

    $lines = [];
    foreach ($answers as $key => $value) {
        $lines[] = $key . '=' . $value;
    }

    file_put_contents($env_path, implode("\n", $lines) . "\n");
    ok('Wrote .env');
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
        fail('Schema delta failed: ' . mysqli_error(Database::connection()) . "\n" . $sql);
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
            // NOT NULL/FK at the end. Confirmed happening in practice.
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

// ---------- Web server ----------
//
// Apache vhost + SELinux/ACL setup can't be done by this script itself (it
// needs root, and this script intentionally never asks for sudo). Printed
// here as a record of the exact steps taken on the dev machine, and as
// instructions for setting up a fresh box the same way.

heading('Web server (run manually, needs sudo)');
echo <<<'SHELL'
sudo tee /etc/httpd/conf.d/duskrail.conf > /dev/null <<'EOF'
<VirtualHost *:80>
    ServerName duskrail.local
    DocumentRoot /path/to/DuskRail

    <Directory /path/to/DuskRail>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog /var/log/httpd/duskrail-error.log
    CustomLog /var/log/httpd/duskrail-access.log combined
</VirtualHost>
EOF
echo "127.0.0.1 duskrail.local" | sudo tee -a /etc/hosts > /dev/null
sudo setfacl -m u:apache:x /path/to
sudo setsebool -P httpd_enable_homedirs on
sudo semanage fcontext -a -t httpd_sys_content_t "/path/to/DuskRail(/.*)?"
sudo restorecon -Rv /path/to/DuskRail
sudo apachectl configtest && sudo systemctl reload httpd

SHELL;

heading('Done');
echo "DuskRail is set up.\n";
