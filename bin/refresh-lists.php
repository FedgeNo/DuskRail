<?php

declare(strict_types=1);

/**
 * Refreshes the two published lists this project depends on but doesn't own:
 * IANA's root-zone TLD list (TLDs - what makes a hostname a real hostname)
 * and Mozilla's Public Suffix List (PublicSuffixList - what makes two
 * hostnames the same owner). Run weekly by the duskrail-refresh-lists systemd
 * timer; also run once by bin/install.php, so a fresh checkout has both
 * before its first crawl.
 *
 * Both lists change slowly - a handful of TLD delegations a year, a steady
 * trickle of PSL entries - so weekly is comfortably more often than they move
 * while keeping a crawler that runs for months off a badly stale copy.
 *
 * This exists as a scheduled job rather than a lazy refresh inside the
 * crawler because the crawler shouldn't be the thing that discovers a list
 * has expired: doing it there means several workers racing for the same
 * download mid-crawl, needing a lock between them, and a stalled fetch
 * showing up as a stalled crawl. Here it's one process, on its own schedule,
 * where a failure is a failure of this job and nothing else.
 *
 * Exits non-zero if either list couldn't be refreshed *and* there's no usable
 * cached copy to fall back on - the case that actually needs someone's
 * attention, as opposed to one bad week against an unreachable server.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

/**
 * Reports on one list's refresh and says whether it left the install in a
 * working state. A failed fetch over a cache that already exists is a
 * warning: everything keeps working on last week's copy, which for lists
 * that change this slowly is no practical difference.
 */
function report(string $name, bool $refreshed, bool $cached, ?int $ageSeconds): bool
{
    if ($refreshed) {
        echo '[ ok ] ' . $name . ' refreshed.
';

        return true;
    }

    if (!$cached) {
        echo '[fail] ' . $name . ' could not be fetched and no cached copy exists.
';

        return false;
    }

    echo '[warn] ' . $name . ' could not be fetched, keeping the cached copy ('
        . ($ageSeconds !== null ? floor($ageSeconds / 86400) . ' day(s) old' : 'age unknown') . ').
';

    return true;
}

$healthy = true;

$refreshed = TLDs::refresh();
$healthy = report('IANA TLD list', $refreshed, TLDs::isCached(), TLDs::cacheAgeSeconds()) && $healthy;

$refreshed = PublicSuffixList::refresh();
$healthy = report('Public Suffix List', $refreshed, PublicSuffixList::isCached(), PublicSuffixList::cacheAgeSeconds()) && $healthy;

exit($healthy ? 0 : 1);
