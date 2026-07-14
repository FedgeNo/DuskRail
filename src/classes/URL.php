<?php

declare(strict_types=1);

class URL
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

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

        $this -> pathGiven = isset($parts['path']) && $parts['path'] !== '';
        $this -> path = $this -> pathGiven ? $parts['path'] : '/';

        // Always resolved to a real port number (falling back to the scheme's
        // default) rather than left null - callers that need to actually open
        // a connection (e.g. cURL's CURLOPT_PORT) shouldn't each have to
        // re-derive the default port themselves.
        $this -> port = $parts['port'] ?? self::DEFAULT_PORTS[$this -> scheme] ?? null;

        $this -> queryGiven = isset($parts['query']);
        $this -> queryParameters = [];
        if ($this -> queryGiven) {
            parse_str($parts['query'], $this -> queryParameters);
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
     */
    public function isValid(): bool
    {
        return in_array($this -> scheme, ['http', 'https'], true) && $this -> host !== '';
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
            $url .= '?' . http_build_query($this -> queryParameters);
        }

        return $url;
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
