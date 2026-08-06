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
$named = robots_test_host('User-agent: ClaudeBot' . $nl . 'Disallow: /' . $nl . $nl . 'User-agent: *' . $nl . 'Disallow: /admin/');
assert_false('named-bot Disallow does not bleed', $named -> isDisallowed('/'));
assert_true('wildcard group still applies', $named -> isDisallowed('/admin/x'));

// A directive-less group followed by a named group must not merge.
$signal = robots_test_host('User-agent: *' . $nl . 'Allow: /' . $nl . $nl . 'User-agent: ClaudeBot' . $nl . 'Disallow: /');
assert_false('later named group starts fresh', $signal -> isDisallowed('/page'));

// A robots.txt whose only directives are for a named bot leaves the
// wildcard crawler entirely unrestricted.
$namedOnly = robots_test_host('User-agent: GPTBot' . $nl . 'Disallow: /');
assert_false('named-only file restricts nothing here', $namedOnly -> isDisallowed('/anything'));

// Comment and blank lines are invisible to grouping.
$commented = robots_test_host('# banner' . $nl . 'User-agent: *' . $nl . $nl . '# note' . $nl . 'Disallow: /x/');
assert_true('comments do not break the group', $commented -> isDisallowed('/x/y'));
