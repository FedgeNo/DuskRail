<?php

declare(strict_types=1);

class Label extends HTMLObject
{
    public string $tagName = 'label';
    public ?string $for = null;

    public function toDOM(): \DOMElement
    {
        if ($this -> for !== null) {
            $this -> attributes['for'] = $this -> for;
        }

        return parent::toDOM();
    }
}
