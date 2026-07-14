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
        $parts = parse_url($url);

        $this -> scheme = strtolower($parts['scheme'] ?? '');
        $this -> host = strtolower($parts['host'] ?? '');

        $this -> pathGiven = isset($parts['path']) && $parts['path'] !== '';
        $this -> path = $this -> pathGiven ? $parts['path'] : '/';

        $port = $parts['port'] ?? null;
        $this -> port = ($port !== null && $port !== (self::DEFAULT_PORTS[$this -> scheme] ?? null)) ? $port : null;

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

    public function toString(): string
    {
        $url = $this -> scheme . '://' . $this -> host;

        if ($this -> port !== null) {
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
