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

mysqli_report(MYSQLI_REPORT_OFF);

$connection = mysqli_connect(
    $config['host'],
    $config['username'],
    $config['password'],
    '',
    $config['port']
);

if ($connection !== false) {
    $exists = mysqli_query($connection, "SHOW DATABASES LIKE '" . mysqli_real_escape_string($connection, $config['database']) . "'");

    if ($exists !== false && mysqli_num_rows($exists) > 0) {
        ok('Database "' . $config['database'] . '" exists and credentials work');
    } else {
        mysqli_query($connection, 'CREATE DATABASE `' . $config['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        ok('Created database "' . $config['database'] . '"');
    }

    mysqli_close($connection);
} else {
    warn('Could not connect as "' . $config['username'] . '" - the database/user may not exist yet.');

    $root_user = prompt('MariaDB root (or admin) username to create it', 'root');
    $root_pass = prompt('MariaDB root (or admin) password', '');

    $root_connection = mysqli_connect($config['host'], $root_user, $root_pass, '', $config['port']);

    if ($root_connection === false) {
        fail('Could not connect as "' . $root_user . '" either. Create the database/user manually and re-run this script.');
    }

    mysqli_query($root_connection, 'CREATE DATABASE IF NOT EXISTS `' . $config['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    mysqli_query($root_connection, "CREATE USER IF NOT EXISTS '" . mysqli_real_escape_string($root_connection, $config['username']) . "'@'" . mysqli_real_escape_string($root_connection, $config['host']) . "' IDENTIFIED BY '" . mysqli_real_escape_string($root_connection, $config['password']) . "'");
    mysqli_query($root_connection, "GRANT ALL PRIVILEGES ON `" . $config['database'] . "`.* TO '" . mysqli_real_escape_string($root_connection, $config['username']) . "'@'" . mysqli_real_escape_string($root_connection, $config['host']) . "'");
    mysqli_query($root_connection, 'FLUSH PRIVILEGES');
    mysqli_close($root_connection);

    ok('Created database "' . $config['database'] . '" and user "' . $config['username'] . '"');
}

// ---------- Schema ----------
//
// Deltas below run in the order they were actually made, oldest first. Each
// has a `check` (does the live database already reflect this delta?) and an
// `apply` (the SQL to run if not). `check` lets this stay idempotent and
// self-healing even if a delta was applied by hand outside this script.

function table_exists(\mysqli $connection, string $table): bool
{
    $result = mysqli_query($connection, "SHOW TABLES LIKE '" . mysqli_real_escape_string($connection, $table) . "'");

    return $result !== false && mysqli_num_rows($result) > 0;
}

function column_exists(\mysqli $connection, string $table, string $column): bool
{
    $result = mysqli_query($connection, 'SHOW COLUMNS FROM `' . $table . '` LIKE \'' . mysqli_real_escape_string($connection, $column) . '\'');

    return $result !== false && mysqli_num_rows($result) > 0;
}

function run_sql(\mysqli $connection, string $sql): void
{
    if (!mysqli_query($connection, $sql)) {
        fail('Schema delta failed: ' . mysqli_error($connection) . "\n" . $sql);
    }
}

function schema_deltas(): array
{
    return [
        [
            'name' => 'create_items_and_links_tables',
            'check' => fn (\mysqli $c) => table_exists($c, 'Items') && table_exists($c, 'Links'),
            'apply' => function (\mysqli $c): void {
                run_sql($c, '
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

                run_sql($c, '
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
            'check' => fn (\mysqli $c) => column_exists($c, 'Items', 'url'),
            'apply' => function (\mysqli $c): void {
                run_sql($c, 'ALTER TABLE `Items` ADD COLUMN `url` varchar(767) NOT NULL AFTER `itemId`, ADD UNIQUE KEY `url` (`url`)');
            },
        ],
        [
            'name' => 'add_crawledtime_to_items',
            'check' => fn (\mysqli $c) => column_exists($c, 'Items', 'crawledTime'),
            'apply' => function (\mysqli $c): void {
                run_sql($c, 'ALTER TABLE `Items` ADD COLUMN `crawledTime` int(10) unsigned DEFAULT NULL');
            },
        ],
    ];
}

heading('Checking schema');

$connection = Database::connection();

run_sql($connection, '
CREATE TABLE IF NOT EXISTS `Migrations` (
  `migrationId` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `appliedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migrationId`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

foreach (schema_deltas() as $delta) {
    $name = $delta['name'];
    $already_recorded = mysqli_query($connection, "SELECT 1 FROM `Migrations` WHERE `name` = '" . mysqli_real_escape_string($connection, $name) . "'");

    if ($already_recorded !== false && mysqli_num_rows($already_recorded) > 0) {
        continue;
    }

    if (($delta['check'])($connection)) {
        ok('Delta "' . $name . '" already satisfied, recording it');
    } else {
        ($delta['apply'])($connection);
        ok('Applied delta "' . $name . '"');
    }

    run_sql($connection, "INSERT INTO `Migrations` (`name`) VALUES ('" . mysqli_real_escape_string($connection, $name) . "')");
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
