<?php

declare(strict_types=1);

class Link extends HTMLVoidElement
{
    public string $tagName = 'link';
    public ?string $rel = null;
    public ?string $href = null;

    public function toDOM(): \DOMElement
    {
        if ($this -> rel !== null) {
            $this -> attributes['rel'] = $this -> rel;
        }

        if ($this -> href !== null) {
            $this -> attributes['href'] = $this -> href;
        }

        return parent::toDOM();
    }
}
