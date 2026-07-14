<?php

declare(strict_types=1);

class Title extends HTMLObject
{
    public string $tagName = 'title';

    public function __construct(?string $text = null)
    {
        parent::__construct();

        if ($text !== null) {
            $this -> contents[] = $text;
        }
    }
}
