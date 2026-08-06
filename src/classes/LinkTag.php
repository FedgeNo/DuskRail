<?php

declare(strict_types=1);

/**
 * The <link> HTML element (stylesheets, favicons, ...) - named LinkTag, not
 * Link, because Link is already the Links-table DB model (matching Item's
 * Items-table naming convention). Anchor already isn't named "A" for the
 * same kind of reason - a primitive whose literal tag name collides with a
 * more important domain concept gets a descriptive name instead.
 */
class LinkTag extends HTMLVoidElement
{
    public string $tagName = 'link';
    public ?string $rel = null;
    public ?string $href = null;
    public ?string $type = null;

    public function toDOM(): \DOMElement
    {
        if ($this -> rel !== null) {
            $this -> attributes['rel'] = $this -> rel;
        }

        if ($this -> href !== null) {
            $this -> attributes['href'] = $this -> href;
        }

        if ($this -> type !== null) {
            $this -> attributes['type'] = $this -> type;
        }

        return parent::toDOM();
    }
}
