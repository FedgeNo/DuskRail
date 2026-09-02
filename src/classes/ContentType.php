<?php

declare(strict_types=1);

/**
 * Parses a Content-Type header value into its bare MIME type and charset
 * parameter. HTML can be served under a few different types (plain HTML,
 * XHTML served as XML, ...) - isHTML() covers the ones actually worth
 * treating as HTML rather than tracking each spelling at every call site.
 */
class ContentType
{
    private const HTML_TYPES = [
        'text/html',
        'application/xhtml+xml',
    ];

    public string $type;
    public ?string $charset = null;

    public function __construct(string $headerValue)
    {
        $segments = explode(';', $headerValue);

        $this -> type = strtolower(trim($segments[0]));

        foreach (array_slice($segments, 1) as $parameter) {
            if (preg_match('/charset\s*=\s*"?([^";]+)"?/i', $parameter, $match)) {
                $this -> charset = trim($match[1]);
                break;
            }
        }
    }

    public function isHTML(): bool
    {
        return in_array($this -> type, self::HTML_TYPES, true);
    }

    public function isImage(): bool
    {
        return str_starts_with($this -> type, 'image/');
    }

    public function isPDF(): bool
    {
        return $this -> type === 'application/pdf';
    }

    public function isPlainText(): bool
    {
        return $this -> type === 'text/plain';
    }
}
