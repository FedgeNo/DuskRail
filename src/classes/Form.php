<?php

declare(strict_types=1);

class Form extends HTMLObject
{
    public string $tagName = 'form';
    public ?string $action = null;
    public ?string $method = null;

    public function toDOM(): \DOMElement
    {
        if ($this -> action !== null) {
            $this -> attributes['action'] = $this -> action;
        }

        if ($this -> method !== null) {
            $this -> attributes['method'] = $this -> method;
        }

        return parent::toDOM();
    }
}
