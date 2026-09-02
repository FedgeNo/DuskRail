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

$dumpError = tmpfile();
$gzipError = tmpfile();
$environment = getenv();
$environment['MYSQL_PWD'] = $config['password'];
$dumpCommand = [
    'mysqldump', '--single-transaction', '--quick', '--skip-lock-tables', '--skip-triggers', '--no-tablespaces',
    '-h', $config['host'],
    '-P', (string) $config['port'],
    '-u', $config['username'],
    $config['database'],
];

// Two independently supervised processes rather than a shell pipeline: a
// successful gzip must never hide a failed mysqldump. Command arrays also
// avoid a shell entirely, and MYSQL_PWD stays out of world-readable argv.
$gzip = proc_open(
    ['gzip', '-c'],
    [0 => ['pipe', 'r'], 1 => ['file', $databaseFile, 'w'], 2 => $gzipError],
    $gzipPipes
);
$dump = is_resource($gzip) ? proc_open(
    $dumpCommand,
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => $dumpError],
    $dumpPipes,
    null,
    $environment
) : false;

if (is_resource($dump) && is_resource($gzip)) {
    stream_copy_to_stream($dumpPipes[1], $gzipPipes[0]);
    fclose($dumpPipes[1]);
    fclose($gzipPipes[0]);
} elseif (is_resource($gzip) && isset($gzipPipes[0]) && is_resource($gzipPipes[0])) {
    fclose($gzipPipes[0]);
}

$dumpStatus = is_resource($dump) ? proc_close($dump) : 1;
$gzipStatus = is_resource($gzip) ? proc_close($gzip) : 1;

rewind($dumpError);
rewind($gzipError);
$errors = trim(stream_get_contents($dumpError) . "\n" . stream_get_contents($gzipError));
fclose($dumpError);
fclose($gzipError);

if ($dumpStatus !== 0 || $gzipStatus !== 0 || !backup_contains_schema($databaseFile)) {
    fwrite(STDERR, 'Database dump failed.
');

    if ($errors !== '') {
        fwrite(STDERR, $errors . "\n");
    }

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

/** A real DuskRail dump necessarily contains at least one CREATE TABLE. */
function backup_contains_schema(string $path): bool
{
    if (!is_file($path) || filesize($path) === 0) {
        return false;
    }

    $handle = @gzopen($path, 'rb');

    if ($handle === false) {
        return false;
    }

    $sample = '';

    while (!gzeof($handle) && strlen($sample) < 2 * 1024 * 1024) {
        $sample .= (string) gzread($handle, 65536);
    }

    gzclose($handle);

    return str_contains($sample, 'CREATE TABLE');
}
