<?php

declare(strict_types=1);

/**
 * IPAddress::isPubliclyRoutable() - the check that decides whether a
 * hostname's resolved address is somewhere this crawler will send a request.
 * Only the pure classification is exercised here; hostResolvesPublicly()
 * needs a resolver and belongs to no test that has to run offline.
 */

// This machine, and the network it's on.
assert_false('loopback refused', IPAddress::isPubliclyRoutable('127.0.0.1'));
assert_false('RFC 1918 10/8 refused', IPAddress::isPubliclyRoutable('10.0.0.1'));
assert_false('RFC 1918 192.168/16 refused', IPAddress::isPubliclyRoutable('192.168.1.1'));
assert_false('RFC 1918 172.16/12 refused', IPAddress::isPubliclyRoutable('172.16.0.1'));
assert_false('unspecified address refused', IPAddress::isPubliclyRoutable('0.0.0.0'));

// The cloud metadata endpoint - the single most valuable thing an SSRF can
// reach on a hosted machine, and an ordinary link-local address.
assert_false('cloud metadata endpoint refused', IPAddress::isPubliclyRoutable('169.254.169.254'));

// Ranges filter_var's own flags don't cover.
assert_false('carrier-grade NAT refused', IPAddress::isPubliclyRoutable('100.64.0.1'));
assert_false('carrier-grade NAT top of range refused', IPAddress::isPubliclyRoutable('100.127.255.255'));
assert_false('IETF protocol assignments refused', IPAddress::isPubliclyRoutable('192.0.0.1'));
assert_false('benchmarking range refused', IPAddress::isPubliclyRoutable('198.18.0.1'));
assert_false('multicast refused', IPAddress::isPubliclyRoutable('224.0.0.1'));

// The addresses just outside those blocks are ordinary public ones - a
// prefix compared a bit too generously would take real sites with it.
assert_true('just past carrier-grade NAT is public', IPAddress::isPubliclyRoutable('100.128.0.1'));
assert_true('just past the benchmarking range is public', IPAddress::isPubliclyRoutable('198.20.0.1'));
assert_true('just below multicast is public', IPAddress::isPubliclyRoutable('223.255.255.255'));
assert_true('just past RFC 1918 172.16/12 is public', IPAddress::isPubliclyRoutable('172.32.0.1'));
assert_true('ordinary public IPv4 accepted', IPAddress::isPubliclyRoutable('8.8.8.8'));

// IPv6, including the spellings that reach an IPv4 private address anyway.
assert_false('IPv6 loopback refused', IPAddress::isPubliclyRoutable('::1'));
assert_false('IPv6 link-local refused', IPAddress::isPubliclyRoutable('fe80::1'));
assert_false('IPv6 unique-local refused', IPAddress::isPubliclyRoutable('fc00::1'));
assert_false('IPv4-mapped loopback refused', IPAddress::isPubliclyRoutable('::ffff:127.0.0.1'));
assert_false('NAT64-wrapped loopback refused', IPAddress::isPubliclyRoutable('64:ff9b::7f00:1'));
assert_false('IPv6 documentation range refused', IPAddress::isPubliclyRoutable('2001:db8::1'));
assert_true('ordinary public IPv6 accepted', IPAddress::isPubliclyRoutable('2606:4700:4700::1111'));

// Anything that isn't an address at all is not an address this crawler may
// connect to either.
assert_false('garbage refused', IPAddress::isPubliclyRoutable('not an ip'));
assert_false('empty string refused', IPAddress::isPubliclyRoutable(''));
assert_false('out-of-range octet refused', IPAddress::isPubliclyRoutable('999.1.1.1'));
assert_false('hostname refused', IPAddress::isPubliclyRoutable('example.com'));

// Resolution is fail-closed: an empty answer and a private answer are not a
// Boolean "nothing objected" success that a later DNS lookup may change.
assert_same('empty hostname has no approved addresses', [], IPAddress::publicAddressesFor(''));
assert_same('localhost has no approved addresses', [], IPAddress::publicAddressesFor('localhost'));
