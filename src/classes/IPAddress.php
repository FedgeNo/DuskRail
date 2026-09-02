<?php

declare(strict_types=1);

/**
 * Whether an IP address is one this crawler is willing to talk to at all.
 *
 * URL::isValid()'s TLD gate stops a page linking the crawler straight at
 * "http://127.0.0.1/" or "http://169.254.169.254/", because no IP literal has
 * a real TLD as its last label. It cannot stop the same thing spelled as a
 * name: anyone who owns a domain can publish "internal.example.com. A
 * 127.0.0.1" and hand out a link to it, and every check that only reads the
 * URL string will pass it. The address a hostname resolves to is the thing
 * that actually decides which machine gets the request, so that's what this
 * asks about.
 *
 * filter_var's own range flags cover most of it (loopback, RFC 1918,
 * link-local - the cloud metadata endpoint included - and the IPv6
 * equivalents). The ranges below are the ones it doesn't know about but that
 * still aren't somewhere on the public internet.
 */
class IPAddress
{
    /**
     * Extra CIDR blocks refused on top of filter_var's private/reserved
     * flags, as [network, prefix length in bits].
     */
    private const BLOCKED_RANGES = [
        ['100.64.0.0', 10],   // RFC 6598 carrier-grade NAT
        ['192.0.0.0', 24],    // RFC 6890 IETF protocol assignments
        ['198.18.0.0', 15],   // RFC 2544 benchmarking
        ['224.0.0.0', 4],     // multicast
        ['64:ff9b::', 96],    // RFC 6052 NAT64 - a v4 private address in v6 clothing
        ['2001:db8::', 32],   // documentation range
    ];

    /**
     * Whether $address is a real, publicly routable internet address - false
     * for anything that isn't a valid IP at all, and for every address that
     * belongs to this machine, this network, or a reserved block.
     */
    public static function isPubliclyRoutable(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED_RANGES as [$network, $prefixBits]) {
            if (self::isWithin($address, $network, $prefixBits)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every address $hostname resolves to, but only when the complete answer
     * set is non-empty and publicly routable. One private answer refuses the
     * whole name: a host publishing both a public A and a private AAAA gets to
     * choose which one an ordinary client reaches, so callers must never pick
     * from a mixed set.
     *
     * The returned addresses are the security decision, not merely evidence
     * for a later Boolean check. HTTPConnection pins them with CURLOPT_RESOLVE
     * and Chromium connects through OutboundProxy, which opens its socket to
     * one of these exact addresses. DNS is therefore not asked a second time
     * between validation and connection.
     *
     * @return list<string> Empty when resolution failed or any answer is not
     * publicly routable.
     */
    public static function publicAddressesFor(string $hostname): array
    {
        static $answers = [];

        $hostname = strtolower(trim($hostname, '.'));

        if ($hostname === '') {
            return [];
        }

        if (filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return self::isPubliclyRoutable($hostname) ? [$hostname] : [];
        }

        if (array_key_exists($hostname, $answers)) {
            return $answers[$hostname];
        }

        $addresses = @gethostbynamel($hostname) ?: [];

        foreach (@dns_get_record($hostname, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        $addresses = array_values(array_unique(array_map('strtolower', $addresses)));

        if ($addresses === []) {
            return $answers[$hostname] = [];
        }

        foreach ($addresses as $address) {
            if (!self::isPubliclyRoutable($address)) {
                return $answers[$hostname] = [];
            }
        }

        return $answers[$hostname] = $addresses;
    }

    public static function hostResolvesPublicly(string $hostname): bool
    {
        return self::publicAddressesFor($hostname) !== [];
    }

    /**
     * Whether $address falls inside the $prefixBits-long CIDR block starting
     * at $network. Compared as packed bytes rather than by parsing either
     * side into a number, so one implementation covers IPv4 and IPv6 alike
     * (inet_pton yields 4 bytes for one and 16 for the other; an address of a
     * different length to the network simply isn't in it).
     */
    private static function isWithin(string $address, string $network, int $prefixBits): bool
    {
        $packedAddress = @inet_pton($address);
        $packedNetwork = @inet_pton($network);

        if ($packedAddress === false || $packedNetwork === false || strlen($packedAddress) !== strlen($packedNetwork)) {
            return false;
        }

        $wholeBytes = intdiv($prefixBits, 8);
        $remainingBits = $prefixBits % 8;

        if ($wholeBytes > 0 && strncmp($packedAddress, $packedNetwork, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        // The partial byte: keep only the bits the prefix actually covers,
        // discarding the rest of that byte on both sides before comparing.
        $mask = chr(0xFF << (8 - $remainingBits) & 0xFF);

        return ($packedAddress[$wholeBytes] & $mask) === ($packedNetwork[$wholeBytes] & $mask);
    }
}
