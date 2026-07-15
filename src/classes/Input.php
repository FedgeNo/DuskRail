<?php

declare(strict_types=1);

class Input extends HTMLVoidElement
{
    public string $tagName = 'input';
    public ?string $type = null;
    public ?string $name = null;
    public ?string $value = null;
    public ?string $placeholder = null;
    public bool $checked = false;

    public function toDOM(): \DOMElement
    {
        if ($this -> type !== null) {
            $this -> attributes['type'] = $this -> type;
        }

        if ($this -> checked) {
            $this -> attributes['checked'] = 'checked';
        }

        if ($this -> name !== null) {
            $this -> attributes['name'] = $this -> name;
        }

        if ($this -> value !== null) {
            $this -> attributes['value'] = $this -> value;
        }

        if ($this -> placeholder !== null) {
            $this -> attributes['placeholder'] = $this -> placeholder;
        }

        return parent::toDOM();
    }
}
