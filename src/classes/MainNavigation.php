<?php

declare(strict_types=1);

class MainNavigation extends HTMLObject
{
    public string $tagName = 'nav';
    public ?string $class = 'MainNavigation navbar navbar-expand navbar-dark bg-dark fixed-top';

    public function toDOM(): \DOMElement
    {
        $config = require ROOT_DIR . '/src/config.php';

        $brand = new Anchor(ServerURL::absolute('/'), $config['siteTitle']);
        $brand -> class = 'NavBrand navbar-brand';

        // Bootstrap's plain .navbar has no horizontal padding of its own
        // (that's normally a .container's job) - without this wrapper the
        // brand text sits flush against the browser edge instead of lining
        // up with the page content below it.
        $container = new Div();
        $container -> class = 'NavContainer';
        $container -> addContent($brand);

        $this -> addContent($container);

        return parent::toDOM();
    }
}
