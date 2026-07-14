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

    public function __construct(string $url)
    {
        $parts = parse_url($url);

        $this -> scheme = strtolower($parts['scheme'] ?? '');
        $this -> host = strtolower($parts['host'] ?? '');
        $this -> path = $parts['path'] ?? '/';

        if ($this -> path === '') {
            $this -> path = '/';
        }

        $port = $parts['port'] ?? null;
        $this -> port = ($port !== null && $port !== (self::DEFAULT_PORTS[$this -> scheme] ?? null)) ? $port : null;

        $this -> queryParameters = [];
        if (isset($parts['query'])) {
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
}
