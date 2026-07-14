<?php

declare(strict_types=1);

class Anchor extends HTMLObject
{
    public string $tagName = 'a';
    public ?string $href = null;

    public function __construct(?string $href = null, ?string $text = null)
    {
        parent::__construct();

        $this -> href = $href;

        if ($text !== null) {
            $this -> contents[] = $text;
        }
    }

    public function toDOM(): \DOMElement
    {
        if ($this -> href !== null) {
            $this -> attributes['href'] = $this -> href;
        }

        return parent::toDOM();
    }
}
