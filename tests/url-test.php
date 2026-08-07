<?php

declare(strict_types=1);

// Normalization: scheme upgrade, default ports, tracking params, image
// query strings, parameter ordering.
assert_same('http upgrades to https', 'https://example.com/', (new URL('http://example.com/')) -> toString());
assert_same('explicit :80 drops with the upgrade', 'https://example.com/foo', (new URL('http://example.com:80/foo')) -> toString());
assert_same('non-default port survives', 'https://example.com:8080/foo', (new URL('http://example.com:8080/foo')) -> toString());
assert_same('default :443 is not spelled out', 'https://example.com/foo', (new URL('https://example.com:443/foo')) -> toString());
assert_same('tracking parameters stripped', 'https://example.com/a?q=1', (new URL('https://example.com/a?q=1&utm_source=x&fbclid=y')) -> toString());
assert_same('image cache-buster stripped', 'https://example.com/img.jpg', (new URL('https://example.com/img.jpg?v=3')) -> toString());
assert_same('dynamic image endpoint keeps its query', 'https://example.com/getimage.php?id=123', (new URL('https://example.com/getimage.php?id=123')) -> toString());
assert_same('query parameters sort', 'https://example.com/a?a=2&b=1', (new URL('https://example.com/a?b=1&a=2')) -> toString());
assert_same('repeated names keep every value', 'https://example.com/a?tag=x&tag=y', (new URL('https://example.com/a?tag=x&tag=y')) -> toString());
assert_same('names are not rewritten', 'https://example.com/a?a.b=1', (new URL('https://example.com/a?a.b=1')) -> toString());
assert_same('raw space in path percent-encodes', 'https://example.com/a%20b.png', (new URL('https://example.com/a b.png')) -> toString());

// Round-trip stability - parsing toString() output must reproduce it.
foreach (['https://example.com/a?tag%5B%5D=x&tag%5B%5D=y', 'https://example.com/a?q=hello+world', 'https://example.com:8080/x'] as $url) {
    assert_same('round-trip stable: ' . $url, $url, (new URL((new URL($url)) -> toString())) -> toString());
}

// Validity: the TLD gate is the SSRF defense.
assert_false('IPv4 literal rejected', (new URL('http://127.0.0.1/')) -> isValid());
assert_false('IPv6 literal rejected', (new URL('http://[::1]/')) -> isValid());
assert_false('single-label host rejected', (new URL('http://fileserver/')) -> isValid());
assert_false('javascript: rejected', (new URL('javascript:alert(1)')) -> isValid());
assert_false('mailto: rejected', (new URL('mailto:a@example.com')) -> isValid());
assert_false('empty href rejected', (new URL('')) -> isValid());
assert_true('real host accepted', (new URL('https://example.com/')) -> isValid());

// OAuth trap detection.
assert_true('oauth authorize URL flagged', (new URL('https://accounts.example.com/auth?client_id=1&redirect_uri=2&response_type=code')) -> isLikelyOAuthURL());
assert_false('ordinary query not flagged', (new URL('https://example.com/?client_id=1')) -> isLikelyOAuthURL());

// RFC 3986 resolution.
$base = new URL('https://example.com/dir/page.html?x=1');
assert_same('absolute reference wins', 'https://other.example.com/z', $base -> resolve(new URL('https://other.example.com/z')) -> toString());
assert_same('protocol-relative keeps scheme', 'https://other.example.com/z', $base -> resolve(new URL('//other.example.com/z')) -> toString());
assert_same('absolute path replaces path', 'https://example.com/root', $base -> resolve(new URL('/root')) -> toString());
assert_same('relative path merges', 'https://example.com/dir/sub/page', $base -> resolve(new URL('sub/page')) -> toString());
assert_same('dot-dot climbs', 'https://example.com/a/b', $base -> resolve(new URL('../a/b')) -> toString());
assert_same('query-only keeps path', 'https://example.com/dir/page.html?only=q', $base -> resolve(new URL('?only=q')) -> toString());

// An absurdly long URL still parses and normalizes - the crawler truncates
// it to the column width when storing, which is only safe if toString()
// itself never fails on one.
$absurd = 'https://example.com/' . str_repeat('a', 2000);
assert_same('over-length URL survives normalization', 'https://example.com/' . str_repeat('a', 2000), (new URL($absurd)) -> toString());
assert_true('over-length URL is still valid', (new URL($absurd)) -> isValid());

// pathAndQuery - what robots.txt rules match against.
assert_same('pathAndQuery includes the query', '/search?q=x', (new URL('https://example.com/search?q=x')) -> pathAndQuery());
assert_same('pathAndQuery bare path', '/search', (new URL('https://example.com/search')) -> pathAndQuery());
