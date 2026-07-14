<?php

declare(strict_types=1);

class Script extends HTMLObject
{
    public string $tagName = 'script';
    public ?string $src = null;

    public function toDOM(): \DOMElement
    {
        if ($this -> src !== null) {
            $this -> attributes['src'] = $this -> src;
        }

        return parent::toDOM();
    }
}
