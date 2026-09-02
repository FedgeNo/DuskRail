<?php

declare(strict_types=1);

/**
 * Host::isDisallowed() against hand-built rule sets - the Host is
 * constructed bare (no database), which is all the matcher needs.
 */
function robots_test_host(string $robotsTxt): Host
{
    $host = new Host();
    $host -> robotsTxt = $robotsTxt;
    $host -> robotsTxtFetched = 1;

    return $host;
}

$nl = chr(10);

$redirectHost = new Host();
$redirectHost -> host = 'example.com';
assert_true(
    'same-host robots redirect allowed',
    $redirectHost -> allowsRobotsRedirect(new URL('https://example.com/canonical-robots.txt'))
);
assert_false(
    'cross-host robots redirect refused',
    $redirectHost -> allowsRobotsRedirect(new URL('https://static.example.com/robots.txt'))
);

// Unknown rules fail closed.
$unknown = new Host();
$unknown -> robotsTxtFetched = 0;
assert_true('unknown robots.txt disallows everything', $unknown -> isDisallowed('/anything'));

// No rules at all allows everything.
assert_false('empty robots.txt allows', robots_test_host('') -> isDisallowed('/anything'));

// Plain prefix rules.
$plain = robots_test_host('User-agent: *' . $nl . 'Disallow: /private/');
assert_true('prefix disallow matches', $plain -> isDisallowed('/private/page'));
assert_false('prefix disallow scoped', $plain -> isDisallowed('/public/page'));

// Wildcards and end anchors.
$patterns = robots_test_host('User-agent: *' . $nl . 'Disallow: /*.pdf$' . $nl . 'Disallow: /*?sort=');
assert_true('$ anchors to the end', $patterns -> isDisallowed('/docs/file.pdf'));
assert_false('$ means nothing may follow', $patterns -> isDisallowed('/docs/file.pdf.html'));
assert_true('query rules match pathAndQuery', $patterns -> isDisallowed('/list?sort=price'));
assert_false('query rules need the query', $patterns -> isDisallowed('/list'));

// Longest match wins; ties go to Allow.
$longest = robots_test_host('User-agent: *' . $nl . 'Disallow: /shop/' . $nl . 'Allow: /shop/public/');
assert_true('shorter disallow holds elsewhere', $longest -> isDisallowed('/shop/cart'));
assert_false('longer allow wins inside', $longest -> isDisallowed('/shop/public/list'));

// Group scoping: a named bot's rules never apply to us.
$named = robots_test_host('User-agent: GPTBot' . $nl . 'Disallow: /' . $nl . $nl . 'User-agent: *' . $nl . 'Disallow: /admin/');
assert_false('named-bot Disallow does not bleed', $named -> isDisallowed('/'));
assert_true('wildcard group still applies', $named -> isDisallowed('/admin/x'));

// A directive-less group followed by a named group must not merge.
$signal = robots_test_host('User-agent: *' . $nl . 'Allow: /' . $nl . $nl . 'User-agent: GPTBot' . $nl . 'Disallow: /');
assert_false('later named group starts fresh', $signal -> isDisallowed('/page'));

// A robots.txt whose only directives are for a named bot leaves the
// wildcard crawler entirely unrestricted.
$namedOnly = robots_test_host('User-agent: GPTBot' . $nl . 'Disallow: /');
assert_false('named-only file restricts nothing here', $namedOnly -> isDisallowed('/anything'));

// Comment and blank lines are invisible to grouping.
$commented = robots_test_host('# banner' . $nl . 'User-agent: *' . $nl . $nl . '# note' . $nl . 'Disallow: /x/');
assert_true('comments do not break the group', $commented -> isDisallowed('/x/y'));

// A pattern within the wildcard budget still matches as a pattern.
$wildcard = robots_test_host('User-agent: *' . $nl . 'Disallow: /a*b');
assert_true('wildcard spans any run', $wildcard -> isDisallowed('/axxxb'));
assert_true('wildcard matches an empty run', $wildcard -> isDisallowed('/ab'));
assert_false('wildcard still needs its literals', $wildcard -> isDisallowed('/ba'));

// Past the budget the value stops being a pattern and only its literal
// prefix is kept - which disallows a superset of what the full pattern
// would have, rather than handing a crawled host an exponential regex to
// run against every path this crawler tests.
$explosive = robots_test_host('User-agent: *' . $nl . 'Disallow: /a*b*c*d*e*f*g*h*i*j');
assert_true('over-budget pattern disallows its prefix', $explosive -> isDisallowed('/anything-at-all'));
assert_false('over-budget pattern stops at its prefix', $explosive -> isDisallowed('/b'));

// A file may declare more rules than are read. The cap is what stops one
// host deciding how long every isDisallowed() call costs.
$lines = ['User-agent: *'];

for ($i = 0; $i < 1200; $i++) {
    $lines[] = 'Disallow: /bulk' . $i . '/';
}

$lines[] = 'Disallow: /past-the-cap/';
$capped = robots_test_host(implode($nl, $lines));
assert_true('rules within the cap apply', $capped -> isDisallowed('/bulk0/x'));
assert_false('rules past the cap are not read', $capped -> isDisallowed('/past-the-cap/x'));
