<?php

declare(strict_types=1);

class HTMLDocument extends HTMLObject
{
    public string $tagName = 'html';
    public Head $head;
    public Body $body;

    public function __construct()
    {
        parent::__construct();
        $this -> head = new Head();
        $this -> body = new Body();
    }

    public function addHeadContent(HTMLObject|CData|string|\DOMNode $item): void
    {
        $this -> head -> addContent($item);
    }

    /**
     * Page content goes in the body - the only children <html> itself ever
     * has are <head> and <body>, so the inherited append-to-own-contents
     * behavior could only ever produce invalid markup here.
     */
    public function addContent(HTMLObject|CData|string|\DOMNode $item): void
    {
        $this -> body -> addContent($item);
    }

    public function toDOM(): \DOMElement
    {
        $implementation = new \DOMImplementation();
        $doctype = $implementation -> createDocumentType('html');

        self::$document = $implementation -> createDocument(null, '', $doctype);
        self::$document -> encoding = 'UTF-8';
        self::$document -> formatOutput = true;

        $html = parent::toDOM();
        $html -> appendChild($this -> head -> toDOM());
        $html -> appendChild($this -> body -> toDOM());

        self::$document -> appendChild($html);

        return $html;
    }

    public function __toString(): string
    {
        $html = $this -> toDOM();

        self::fillEmptyNonVoidTags($html);

        return '<!DOCTYPE html>
'
            . self::stripSelfClosingSlash(self::$document -> saveXML(self::$document -> documentElement));
    }

    public function send(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        echo $this;
    }
}
