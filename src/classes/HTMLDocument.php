<?php

declare(strict_types=1);

class HTMLDocument extends HTMLObject
{
    public string $tagName = 'html';

    // Screen readers pick their pronunciation rules from this, and there's no
    // sensible way for them to guess it - an unlabelled document gets read in
    // whatever language the reader happens to be configured for.
    public string $lang = 'en';

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

        $this -> attributes['lang'] = $this -> lang;

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
        // Everything this site renders is untrusted text off the open web,
        // so even with textContent-only DOM building throughout, the pages
        // declare that scripts and styles only ever come from this origin -
        // an injection that somehow got past the first line still has
        // nowhere to load code from. img-src stays open because the image
        // preview deliberately loads the full-size original from the site it
        // was crawled from.
        if (!headers_sent()) {
            header('Content-Security-Policy: '
                . 'default-src \'self\'; '
                . 'img-src \'self\' https: http:; '
                . 'object-src \'none\'; '
                . 'frame-ancestors \'none\'; '
                . 'base-uri \'self\'; '
                . 'form-action \'self\'');
            header('X-Content-Type-Options: nosniff');
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        echo $this;
    }
}
