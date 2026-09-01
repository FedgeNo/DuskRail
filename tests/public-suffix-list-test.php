<?php

declare(strict_types=1);

/**
 * PublicSuffixList::registrableDomain() against the real cached list - the
 * same dependency the URL tests already have on the cached TLD list. Run
 * bin/refresh-lists.php if either is missing.
 */

// The reason this class exists: subdomains of one registered domain are one
// domain, however many of them a wildcard DNS record mints.
assert_same('subdomain collapses to its domain', 'evil.com', PublicSuffixList::registrableDomain('a.evil.com'));
assert_same('deep subdomain collapses too', 'evil.com', PublicSuffixList::registrableDomain('b.c.d.evil.com'));
assert_same('bare domain is already registrable', 'evil.com', PublicSuffixList::registrableDomain('evil.com'));

// And the reason a plain "last two labels" rule won't do: these are two
// unrelated organisations that such a rule would merge into "co.uk".
assert_same('multi-label suffix keeps its owner', 'bbc.co.uk', PublicSuffixList::registrableDomain('www.bbc.co.uk'));
assert_same('another owner under the same suffix', 'theguardian.co.uk', PublicSuffixList::registrableDomain('theguardian.co.uk'));
assert_same('multi-label suffix, deeper host', 'example.co.jp', PublicSuffixList::registrableDomain('shop.example.co.jp'));

// Wildcard rules ("*.ck") and the exception rules that carve names back out
// of them ("!www.ck").
assert_same('wildcard suffix consumes a label', 'test.ck', PublicSuffixList::registrableDomain('test.ck'));
assert_same('exception rule overrides its wildcard', 'www.ck', PublicSuffixList::registrableDomain('www.ck'));
assert_same('exception rule with a host in front', 'city.kawasaki.jp', PublicSuffixList::registrableDomain('www.city.kawasaki.jp'));

// The private section is deliberately not read, so every site on a hosting
// platform counts as that platform - the cheap multiplicity this defends
// against is exactly what the private rules would restore.
assert_same('platform users collapse to the platform', 'github.io', PublicSuffixList::registrableDomain('alice.github.io'));
assert_same('another platform user, same domain', 'github.io', PublicSuffixList::registrableDomain('bob.github.io'));

// Nothing in the list matches: treated as a single-label suffix, which is
// what an as-yet-unlisted TLD should look like.
assert_same('unknown suffix takes one label', 'nothing.invalidtldxyz', PublicSuffixList::registrableDomain('nothing.invalidtldxyz'));

// Shapes that have no registrable domain at all still answer with the
// narrowest true statement rather than a suffix shared with strangers.
assert_same('a bare public suffix answers itself', 'co.uk', PublicSuffixList::registrableDomain('co.uk'));
assert_same('single label answers itself', 'localhost', PublicSuffixList::registrableDomain('localhost'));
assert_same('empty host answers empty', '', PublicSuffixList::registrableDomain(''));

// Case and stray dots are normalized, since these come from crawled markup.
assert_same('uppercase host folds', 'evil.com', PublicSuffixList::registrableDomain('A.EVIL.COM'));
assert_same('trailing root dot ignored', 'evil.com', PublicSuffixList::registrableDomain('a.evil.com.'));
