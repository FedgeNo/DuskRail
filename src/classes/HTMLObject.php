<?php

declare(strict_types=1);

class HTMLObject
{
    protected static \DOMDocument $document;

    public static function currentDocument(): \DOMDocument
    {
        return self::$document;
    }

    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    public string $tagName = 'div';
    public ?string $id = null;
    public ?string $class = null;
    public array $contents = [];
    public array $attributes = [];

    public function __construct()
    {
        $declaring_class = (new \ReflectionProperty(static::class, 'class')) -> getDeclaringClass() -> getName();

        if ($declaring_class === self::class) {
            return; // generic HTML-primitive wrapper (Div, Anchor, etc.) - no class name of its own
        }

        $names = [];
        $class = static::class;

        while ($class !== $declaring_class) {
            $names[] = $class;
            $class = get_parent_class($class);
        }

        if ($names === []) {
            return; // this class is the one that declared $class - nothing further to add
        }

        $base_value = (new \ReflectionClass($declaring_class)) -> getDefaultProperties()['class'];
        $this -> class = trim($base_value . ' ' . implode(' ', array_reverse($names)));
    }

    public function addContent(HTMLObject|CData|string|\DOMNode $item): void
    {
        $this -> contents[] = $item;
    }

    public function toDOM(): \DOMElement
    {
        $element = self::$document -> createElement($this -> tagName);

        if ($this -> id !== null) {
            $element -> setAttribute('id', $this -> id);
        }

        if ($this -> class !== null) {
            $element -> setAttribute('class', $this -> class);
        }

        foreach ($this -> attributes as $name => $value) {
            $element -> setAttribute($name, $value);
        }

        foreach ($this -> contents as $item) {
            $node = $this -> contentToNode($item);
            if ($node !== null) {
                $element -> appendChild($node);
            }
        }

        return $element;
    }

    protected function contentToNode($item): ?\DOMNode
    {
        if ($item instanceof HTMLObject) {
            return $item -> toDOM();
        } elseif ($item instanceof CData) {
            return $item -> toNode();
        } elseif (is_string($item)) {
            return self::$document -> createTextNode($item);
        } elseif ($item instanceof \DOMNode) {
            return self::$document -> importNode($item, true);
        }

        return null;
    }

    /**
     * Render a standalone object (not part of a full HTMLDocument) to an HTML string.
     */
    public function render(): string
    {
        $implementation = new \DOMImplementation();
        self::$document = $implementation -> createDocument();
        self::$document -> formatOutput = true;

        $element = $this -> toDOM();
        self::$document -> appendChild($element);

        self::fillEmptyNonVoidTags($element);

        return self::stripSelfClosingSlash(self::$document -> saveXML($element));
    }

    protected static function fillEmptyNonVoidTags(\DOMElement $root): void
    {
        $xpath = new \DOMXPath(self::$document);

        foreach ($xpath -> query('//*[not(node())]', $root) as $element) {
            if (!in_array($element -> tagName, self::VOID_ELEMENTS, true)) {
                $element -> appendChild(self::$document -> createTextNode(''));
            }
        }
    }

    /**
     * saveXML() self-closes every empty element ("<tag/>"), which is correct
     * XML but not HTML5 - void elements (img, br, ...) should read "<tag>"
     * with no closing tag at all, and fillEmptyNonVoidTags() above already
     * guarantees no non-void element is ever empty enough to self-close. That
     * leaves "/>" only ever appearing at an actual void-element close, since
     * saveXML entity-escapes any bare ">" inside text or attribute values -
     * so this replace can't corrupt real content.
     */
    protected static function stripSelfClosingSlash(string $xml): string
    {
        return str_replace('/>', '>', $xml);
    }
}
