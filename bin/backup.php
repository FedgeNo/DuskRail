<?php

declare(strict_types=1);

/**
 * Backs up everything DuskRail can't regenerate: `php bin/backup.php`.
 *
 * Two artifacts per run, timestamped: a gzipped mysqldump of the database
 * (the index, the link graph, the queue) and a tar of thumbnails/ (originals
 * aren't stored, so a lost thumbnail means refetching the image to remake
 * it). data/ isn't included - the TLD list refetches itself - and neither is
 * var/, which is only ever live run state.
 *
 * Backups land in backups/ inside the project (gitignored) unless a
 * different directory is given as the first argument. The last KEEP_RUNS
 * runs are kept; older ones are deleted so an unattended nightly run can't
 * quietly fill the disk.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

const KEEP_RUNS = 7;

$config = require SRC_DIR . '/config.php';
$directory = $argv[1] ?? ROOT_DIR . '/backups';

if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
    fwrite(STDERR, 'Couldn\'t create ' . $directory . '
');
    exit(1);
}

$stamp = date('Ymd-His');
$databaseFile = $directory . '/duskrail-db-' . $stamp . '.sql.gz';
$thumbnailsFile = $directory . '/duskrail-thumbnails-' . $stamp . '.tar.gz';

// The password goes through the environment, never the command line - argv
// is world-readable in /proc for as long as mysqldump runs.
putenv('MYSQL_PWD=' . $config['password']);

$dumpCommand = 'mysqldump --single-transaction --quick'
    . ' -h ' . escapeshellarg($config['host'])
    . ' -P ' . escapeshellarg((string) $config['port'])
    . ' -u ' . escapeshellarg($config['username'])
    . ' ' . escapeshellarg($config['database'])
    . ' | gzip > ' . escapeshellarg($databaseFile);

passthru($dumpCommand, $dumpStatus);
putenv('MYSQL_PWD');

if ($dumpStatus !== 0 || !is_file($databaseFile) || filesize($databaseFile) === 0) {
    fwrite(STDERR, 'Database dump failed.
');
    @unlink($databaseFile);
    exit(1);
}

echo 'Database: ' . $databaseFile . ' (' . number_format((int) filesize($databaseFile)) . ' bytes)
';

passthru(
    'tar -czf ' . escapeshellarg($thumbnailsFile)
        . ' -C ' . escapeshellarg(ROOT_DIR) . ' thumbnails',
    $tarStatus
);

if ($tarStatus !== 0) {
    fwrite(STDERR, 'Thumbnail archive failed.
');
    @unlink($thumbnailsFile);
    exit(1);
}

echo 'Thumbnails: ' . $thumbnailsFile . ' (' . number_format((int) filesize($thumbnailsFile)) . ' bytes)
';

// Rotation: each run produces one db file and one thumbnails file with the
// same sortable timestamp, so keeping the newest KEEP_RUNS of each kind is
// keeping the newest KEEP_RUNS runs.
foreach (['duskrail-db-', 'duskrail-thumbnails-'] as $prefix) {
    $files = glob($directory . '/' . $prefix . '*') ?: [];
    rsort($files);

    foreach (array_slice($files, KEEP_RUNS) as $old) {
        unlink($old);
        echo 'Rotated out ' . $old . '
';
    }
}

echo 'Done.
';
