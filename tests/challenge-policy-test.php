<?php

declare(strict_types=1);

$page = new URL('https://www.example.com/challenge');

assert_true(
    'challenge may load same host',
    HeadlessBrowser::isAllowedChallengeHost('www.example.com', $page)
);
assert_true(
    'challenge may load sibling on same registrable domain',
    HeadlessBrowser::isAllowedChallengeHost('static.example.com', $page)
);
assert_true(
    'reviewed challenge provider is allowed',
    HeadlessBrowser::isAllowedChallengeHost('challenges.cloudflare.com', $page)
);
assert_false(
    'unrelated public domain is refused',
    HeadlessBrowser::isAllowedChallengeHost('unrelated.example.net', $page)
);
assert_false(
    'provider lookalike is refused',
    HeadlessBrowser::isAllowedChallengeHost('challenges.cloudflare.com.attacker.example', $page)
);
assert_false(
    'IP literal is never a challenge domain',
    HeadlessBrowser::isAllowedChallengeHost('127.0.0.1', $page)
);
