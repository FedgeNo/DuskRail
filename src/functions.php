<?php

declare(strict_types=1);

/**
 * Small pieces of the POSIX process/account API that this project needs.
 *
 * ext-posix provides all of them directly, but it isn't guaranteed to be
 * installed - it's a separate package on several distributions and is
 * genuinely missing on machines that otherwise run this fine. Requiring it
 * would mean refusing to install over something every one of these can do by
 * shelling out to a standard command instead, so each function uses the
 * extension when it's there and falls back when it isn't.
 */

/**
 * The account name owning $path, or null if it can't be determined.
 */
function file_owner_name(string $path): ?string
{
    $ownerId = @fileowner($path);

    if ($ownerId === false) {
        return null;
    }

    if (function_exists('posix_getpwuid')) {
        $owner = posix_getpwuid($ownerId);

        return $owner !== false ? $owner['name'] : null;
    }

    $name = trim((string) shell_exec('stat -c %U ' . escapeshellarg($path) . ' 2>/dev/null'));

    return $name !== '' ? $name : null;
}

/**
 * Whether a local account by this name exists.
 */
function user_exists(string $name): bool
{
    if (function_exists('posix_getpwnam')) {
        return posix_getpwnam($name) !== false;
    }

    return trim((string) shell_exec('getent passwd ' . escapeshellarg($name) . ' 2>/dev/null')) !== '';
}

/**
 * The account this process is running as - only ever used to say so in an
 * error message, so an id is a perfectly good answer when there's no way to
 * turn it into a name.
 */
function current_user_name(): string
{
    if (function_exists('posix_geteuid')) {
        $userId = posix_geteuid();

        if (function_exists('posix_getpwuid')) {
            $user = posix_getpwuid($userId);

            if ($user !== false) {
                return $user['name'];
            }
        }

        return (string) $userId;
    }

    $name = trim((string) shell_exec('id -un 2>/dev/null'));

    return $name !== '' ? $name : 'unknown';
}

/**
 * Kills $pid outright. SIGKILL rather than SIGTERM because every caller here
 * has already established the process is unreachable - a browser left behind
 * by a manager that died without shutting it down.
 */
function kill_process(int $pid): void
{
    if (function_exists('posix_kill')) {
        posix_kill($pid, SIGKILL);

        return;
    }

    exec('kill -9 ' . escapeshellarg((string) $pid) . ' 2>/dev/null');
}
