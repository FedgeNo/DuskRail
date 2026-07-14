<?php

declare(strict_types=1);

class Button extends HTMLObject
{
    public string $tagName = 'button';
    public ?string $type = null;

    public function __construct(?string $text = null)
    {
        parent::__construct();

        if ($text !== null) {
            $this -> contents[] = $text;
        }
    }

    public function toDOM(): \DOMElement
    {
        if ($this -> type !== null) {
            $this -> attributes['type'] = $this -> type;
        }

        return parent::toDOM();
    }
}
