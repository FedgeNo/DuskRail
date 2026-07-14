<?php

declare(strict_types=1);

class MainNavigation extends HTMLObject
{
    public string $tagName = 'nav';
    public ?string $class = 'MainNavigation navbar navbar-expand navbar-dark bg-dark';

    public function toDOM(): \DOMElement
    {
        $config = require ROOT_DIR . '/src/config.php';

        $brand = new Anchor(ServerURL::absolute('/'), $config['siteTitle']);
        $brand -> class = 'NavBrand navbar-brand';

        $this -> addContent($brand);

        return parent::toDOM();
    }
}
