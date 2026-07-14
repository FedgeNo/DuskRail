<?php

declare(strict_types=1);

class HTMLVoidElement extends HTMLObject
{
    public function addContent(HTMLObject|CData|string|\DOMNode $item): void
    {
        throw new \Exception('<' . $this -> tagName . '> is a void element and cannot contain content');
    }
}
