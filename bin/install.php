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

foreach (['mysqli'] as $extension) {
    if (!extension_loaded($extension)) {
        fail('Missing required PHP extension: ' . $extension);
    }
    ok('ext-' . $extension . ' loaded');
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

heading('Checking schema');

$connection = Database::connection();

$tables = mysqli_query($connection, "SHOW TABLES LIKE 'Items'");

if ($tables !== false && mysqli_num_rows($tables) > 0) {
    ok('Schema already applied');
} else {
    $schema_sql = file_get_contents(ROOT_DIR . '/schema.sql');

    if (!mysqli_multi_query($connection, $schema_sql)) {
        fail('Failed to apply schema.sql: ' . mysqli_error($connection));
    }

    // Drain the multi-query result set so the connection is left in a clean
    // state - mysqli refuses further queries on it otherwise.
    do {
        if ($result = mysqli_store_result($connection)) {
            mysqli_free_result($result);
        }
    } while (mysqli_more_results($connection) && mysqli_next_result($connection));

    ok('Applied schema.sql');
}

heading('Done');
echo "DuskRail is set up.\n";
