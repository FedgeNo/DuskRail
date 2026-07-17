<?php

declare(strict_types=1);

class Legend extends HTMLObject
{
    public string $tagName = 'legend';

    public function __construct(?string $text = null)
    {
        parent::__construct();

        if ($text !== null) {
            $this -> contents[] = $text;
        }
    }
}
