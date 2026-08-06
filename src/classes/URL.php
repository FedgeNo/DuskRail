<?php

declare(strict_types=1);

class URL
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    // Only well-known, unambiguous tracking-only params - never anything
    // generic enough ("ref", "source") that a real site might use for actual
    // functional/navigational state. Stripped once here, at construction, so
    // they never make it into $queryParameters at all - toString(),
    // resolve(), and every hash/comparison downstream just never sees them,
    // rather than every caller needing to filter them out itself.
    private const TRACKING_PARAMETERS = [
        // Google Analytics ("urchin" tracking module, historically utmx/urchin.js)
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_id', 'utm_source_platform', 'utm_creative_format', 'utm_marketing_tactic',
        'utm_name', 'utm_reader', 'utm_viz_id',
        // Ad-network click ids
        'gclid', 'gclsrc', 'dclid', 'gbraid', 'wbraid', 'msclkid',
        // Social platforms
        'fbclid', 'twclid', 'ttclid', 'igshid', 'igsh',
        // Email marketing
        'mc_cid', 'mc_eid', '_hsenc', '_hsmi', 'hsCtaTracking', 'vero_id',
        'yclid', 'mkt_tok',
    ];

    // A query string on a URL that already ends in a real image extension is
    // almost never part of the resource's identity - it's a cache-buster
    // (?v=..., a timestamp) or a resize/format hint (?w=300, ?quality=80) the
    // same underlying image gets served under constantly, and keeping it
    // would crawl and store the same picture over and over under "different"
    // URLs. This is deliberately keyed off having a recognized extension
    // already in the path, not applied to every URL - a dynamic image
    // endpoint that has no extension at all (e.g. "/getimage.php?id=123",
    // where the query string *is* the only identifier) is left completely
    // alone.
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif', 'ico', 'tif', 'tiff'];

    public string $scheme;
    public string $host;
    public ?int $port;
    public string $path;
    public array $queryParameters;

    // Whether the original string actually had a path/query component, as
    // opposed to defaulting to one - resolve() needs to tell "no path given"
    // (inherit the base's) apart from "path is /" (an explicit root), and
    // likewise for the query string. Not exposed publicly; nothing outside
    // resolve() needs it.
    private bool $pathGiven;
    private bool $queryGiven;

    public function __construct(string $url)
    {
        // A crawled page's markup is hostile input - parse_url() returns
        // false outright for a sufficiently malformed string (a stray ":" in
        // the wrong place, a garbage port, ...) rather than a partial parse.
        // Treated the same as "nothing parsed", which naturally lands on an
        // empty scheme/host and fails isValid() below.
        $parts = parse_url($url) ?: [];

        $this -> scheme = strtolower($parts['scheme'] ?? '');
        $this -> host = strtolower($parts['host'] ?? '');

        // It's 2026 - a host with no HTTPS at all is rare enough that it's
        // not worth carrying "http" as a second, separate identity for the
        // same page forever. Forced before the port default below is
        // resolved, so a bare "http://host/" (no explicit port) correctly
        // defaults to 443, not 80. If the host genuinely can't do HTTPS, the
        // connection just fails outright and the item gets deleted like any
        // other unrecoverable failure - not retried under a different scheme.
        if ($this -> scheme === 'http') {
            $this -> scheme = 'https';

            // An explicit ":80" belonged to the http URL that was just
            // upgraded - carrying it across would mean opening a TLS
            // connection to a plaintext port, which fails outright and gets
            // the item deleted like any other unrecoverable failure. It goes
            // back to being the scheme's default along with the scheme.
            if (($parts['port'] ?? null) === self::DEFAULT_PORTS['http']) {
                unset($parts['port']);
            }
        }

        $this -> pathGiven = isset($parts['path']) && $parts['path'] !== '';
        $this -> path = $this -> pathGiven ? self::encodePath($parts['path']) : '/';

        // Always resolved to a real port number (falling back to the scheme's
        // default) rather than left null - callers that need to actually open
        // a connection (e.g. cURL's CURLOPT_PORT) shouldn't each have to
        // re-derive the default port themselves.
        $this -> port = $parts['port'] ?? self::DEFAULT_PORTS[$this -> scheme] ?? null;

        $this -> queryGiven = isset($parts['query']);
        $this -> queryParameters = [];
        if ($this -> queryGiven) {
            $this -> queryParameters = self::parseQuery($parts['query']);

            foreach (self::TRACKING_PARAMETERS as $trackingParameter) {
                unset($this -> queryParameters[$trackingParameter]);
            }
        }

        if (self::isImagePath($this -> path)) {
            $this -> queryParameters = [];
        }

        // Canonical query parameter order - two URLs differing only in query
        // string order are the same resource, and sorting here means they
        // normalize to the same toString() output instead of being treated
        // (and re-crawled) as distinct pages.
        ksort($this -> queryParameters);
    }

    /**
     * Whether this is actually a fetchable web resource - false for anything
     * a crawled page's markup might hand back that isn't: "javascript:",
     * "mailto:", "tel:", "data:", a bare "#fragment" or empty href (no
     * scheme/host at all), or a string malformed enough that parsing
     * couldn't recover a host. Callers should drop the URL (and whatever
     * link/img tag produced it) rather than use it any further once this is
     * false - a crawler that tried to fetch every href verbatim would end up
     * "GET"-ing mailto: addresses and JS snippets.
     *
     * Also requires a real, currently-delegated TLD (see TLDs) - this
     * project's whole approach to SSRF hardening in one move: no IP literal
     * (IPv4, or IPv6 - parse_url keeps IPv6's brackets, e.g. "[::1]", which
     * still has no dot-separated label to match) ever has a real TLD as its
     * last label, so this alone rejects a page linking the crawler at
     * "http://127.0.0.1/", a cloud metadata endpoint
     * ("http://169.254.169.254/..."), or an internal address, along with
     * single-label internal hostnames ("http://fileserver/") and made-up
     * internal-only suffixes ("http://app.corp/", "http://db.local/") -
     * without needing a separate, dedicated IP-literal check at all.
     */
    public function isValid(): bool
    {
        if (!in_array($this -> scheme, ['http', 'https'], true) || $this -> host === '') {
            return false;
        }

        $lastDot = strrpos($this -> host, '.');

        return $lastDot !== false && TLDs::isValid(substr($this -> host, $lastDot + 1));
    }

    /**
     * Whether this looks like an OAuth2/OIDC authorization endpoint (a
     * "sign in with..." link) rather than real content - client_id +
     * redirect_uri + response_type together is essentially unique to that
     * flow (it's the standard OAuth2 authorization-request parameter set),
     * so this doesn't need to guess at path conventions ("/login", "/auth",
     * "/openid-connect/..." vary by provider and "/login" alone is too
     * generic to safely rule out real content). These URLs are also a real
     * crawl trap: many providers mint a fresh, single-use "state" parameter
     * per request, so the "same" login link looks like a new, never-seen
     * URL every single time a page links to it.
     */
    public function isLikelyOAuthURL(): bool
    {
        return isset($this -> queryParameters['client_id'])
            && isset($this -> queryParameters['redirect_uri'])
            && isset($this -> queryParameters['response_type']);
    }

    public function toString(): string
    {
        $url = $this -> scheme . '://' . $this -> host;

        // The port property itself always holds a real number, but the
        // default for the scheme is still left out of the normalized string -
        // "https://host/" and "https://host:443/" are the same URL, and only
        // one of those two spellings should be treated as canonical.
        if ($this -> port !== (self::DEFAULT_PORTS[$this -> scheme] ?? null)) {
            $url .= ':' . $this -> port;
        }

        $url .= $this -> path;

        if ($this -> queryParameters !== []) {
            $url .= '?' . $this -> queryString();
        }

        return $url;
    }

    /**
     * The path with its query string attached - what robots.txt rules are
     * matched against (Host::isDisallowed()). A rule like "Disallow: /*?"
     * or "Disallow: /search?q=" is about the query, and matching the bare
     * path would silently ignore every rule of that shape.
     */
    public function pathAndQuery(): string
    {
        return $this -> queryParameters === [] ? $this -> path : $this -> path . '?' . $this -> queryString();
    }

    /**
     * Resolves $relative (typically a link found on this URL's page) against
     * this URL, per RFC 3986 5.3's reference resolution algorithm: an
     * absolute $relative is returned as-is; a protocol-relative one
     * ("//host/path") keeps this URL's scheme; an absolute-path one ("/path")
     * keeps this URL's host but replaces the whole path; and a bare relative
     * one ("path", "../path", "?q=1") is merged against this URL's own path.
     */
    public function resolve(self $relative): self
    {
        $result = (new \ReflectionClass(self::class)) -> newInstanceWithoutConstructor();

        if ($relative -> scheme !== '') {
            $result -> scheme = $relative -> scheme;
            $result -> host = $relative -> host;
            $result -> port = $relative -> port;
            $result -> path = self::removeDotSegments($relative -> path);
            $result -> queryParameters = $relative -> queryParameters;
        } elseif ($relative -> host !== '') {
            $result -> scheme = $this -> scheme;
            $result -> host = $relative -> host;
            $result -> port = $relative -> port;
            $result -> path = self::removeDotSegments($relative -> path);
            $result -> queryParameters = $relative -> queryParameters;
        } else {
            $result -> scheme = $this -> scheme;
            $result -> host = $this -> host;
            $result -> port = $this -> port;

            if (!$relative -> pathGiven) {
                $result -> path = $this -> path;
                $result -> queryParameters = $relative -> queryGiven ? $relative -> queryParameters : $this -> queryParameters;
            } else {
                $result -> path = str_starts_with($relative -> path, '/')
                    ? self::removeDotSegments($relative -> path)
                    : self::removeDotSegments(self::mergePaths($this -> path, $relative -> path));
                $result -> queryParameters = $relative -> queryParameters;
            }
        }

        if (self::isImagePath($result -> path)) {
            $result -> queryParameters = [];
        }

        $result -> pathGiven = true;
        $result -> queryGiven = $result -> queryParameters !== [];

        // A protocol-relative reference ("//host/path") carries a host but no
        // scheme, so its own port (derived while parsing it standalone) may
        // still be null even though $result now has a real scheme inherited
        // from this URL - re-derive the default here rather than leaving it
        // null on the final result.
        $result -> port ??= self::DEFAULT_PORTS[$result -> scheme] ?? null;

        return $result;
    }

    /**
     * Percent-encodes anything in a path that isn't valid there raw -
     * crawled markup routinely has an unencoded space or other character in
     * an href/src (a real one broke this exact class: a raw space in the
     * path meant the HTTP request line itself was malformed, and the server
     * never sent back a response at all - not an error status, no response
     * whatsoever). "+" is only a query-string convention (form-urlencoded
     * data); in a path it's just a literal plus sign, so this always uses
     * "%20" for a space, never "+". Leaves already-valid percent-encoded
     * sequences (%XX) alone rather than re-encoding their "%" into "%25" -
     * the first alternative only matches a "%" that ISN'T followed by two
     * hex digits, i.e. one that's actually raw, not part of an existing escape.
     */
    private static function encodePath(string $path): string
    {
        return preg_replace_callback(
            '/%(?![0-9A-Fa-f]{2})|[^A-Za-z0-9\-._~!$&\'()*+,;=:@\/%]/',
            static fn (array $match): string => rawurlencode($match[0]),
            $path
        );
    }

    /**
     * A query string as its name => value pairs. Deliberately not parse_str():
     * that function exists to rebuild PHP request variables, not to read a URL,
     * and it rewrites names on the way through - "a.b" and "a b" both become
     * "a_b", and "tag[]=x&tag[]=y" becomes a nested array that http_build_query()
     * then re-emits as "tag[0]=x&tag[1]=y". Every one of those is a different
     * URL from the one the page actually linked to, so toString() would hand
     * the crawler an address the site never published.
     *
     * A name that genuinely repeats ("tag=x&tag=y", "tag[]=x&tag[]=y") keeps
     * every value, as a list under that one name - dropping all but the last
     * would be the same kind of lie about which resource this is. Both shapes
     * still read as one key for isset() (isLikelyOAuthURL()) and unset()
     * (TRACKING_PARAMETERS) purposes.
     */
    private static function parseQuery(string $query): array
    {
        $parameters = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = rawurldecode(str_replace('+', ' ', $name));

            if ($name === '') {
                continue;
            }

            $value = rawurldecode(str_replace('+', ' ', $value));

            if (!isset($parameters[$name])) {
                $parameters[$name] = $value;
                continue;
            }

            $parameters[$name] = is_array($parameters[$name]) ? $parameters[$name] : [$parameters[$name]];
            $parameters[$name][] = $value;
        }

        return $parameters;
    }

    /**
     * The query string to put back on the end of toString(). Not
     * http_build_query(): that turns a repeated name's list of values into
     * "name[0]=&name[1]=" indexed keys, which is exactly the rewriting
     * parseQuery() exists to avoid - here each value is emitted under the one
     * name it was actually given. Encoding matches http_build_query()'s
     * otherwise (urlencode, so a space is "+"), since that's the form every
     * URL already in the index was normalized to.
     */
    private function queryString(): string
    {
        $pairs = [];

        foreach ($this -> queryParameters as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $single) {
                $pairs[] = urlencode((string) $name) . '=' . urlencode($single);
            }
        }

        return implode('&', $pairs);
    }

    private static function isImagePath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    /**
     * RFC 3986 5.3's merge(): a relative-path reference resolves against the
     * base's directory (everything up to its last '/'), not its full path -
     * "b" resolved against "/a/x" is "/a/b", not "/a/xb".
     */
    private static function mergePaths(string $basePath, string $referencePath): string
    {
        $lastSlash = strrpos($basePath, '/');

        return $lastSlash === false ? $referencePath : substr($basePath, 0, $lastSlash + 1) . $referencePath;
    }

    /**
     * RFC 3986 5.2.4: collapses "." and ".." path segments (e.g.
     * "/a/../b/./c" -> "/b/c") the way a browser would before requesting the
     * URL, so links found on a page normalize to the same canonical form
     * regardless of how many "../" a particular link happened to use.
     */
    private static function removeDotSegments(string $path): string
    {
        $output = '';

        while ($path !== '') {
            if (str_starts_with($path, '../')) {
                $path = substr($path, 3);
            } elseif (str_starts_with($path, './')) {
                $path = substr($path, 2);
            } elseif (str_starts_with($path, '/./')) {
                $path = '/' . substr($path, 3);
            } elseif ($path === '/.') {
                $path = '/';
            } elseif (str_starts_with($path, '/../')) {
                $path = '/' . substr($path, 4);
                $output = preg_replace('#/?[^/]*$#', '', $output);
            } elseif ($path === '/..') {
                $path = '/';
                $output = preg_replace('#/?[^/]*$#', '', $output);
            } elseif ($path === '.' || $path === '..') {
                $path = '';
            } else {
                preg_match('#^/?[^/]*#', $path, $match);
                $output .= $match[0];
                $path = substr($path, strlen($match[0]));
            }
        }

        return $output;
    }
}
