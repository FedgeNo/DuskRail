<?php

declare(strict_types=1);

class Page
{
    public static function create(string $title): HTMLDocument
    {
        $page = new HTMLDocument();

        $config = require ROOT_DIR . '/src/config.php';

        $charset = new Meta();
        $charset -> charset = 'utf-8';
        $page -> addHeadContent($charset);

        $viewport = new Meta();
        $viewport -> name = 'viewport';
        $viewport -> content = 'width=device-width, initial-scale=1';
        $page -> addHeadContent($viewport);

        $page -> addHeadContent(new Title($title . ' - ' . $config['siteTitle']));
        $page -> addHeadContent(new BootstrapLink());

        // MainNavigation is fixed-top, so it's pulled out of normal flow -
        // without this, its ~56px navbar height would sit on top of the
        // first bit of every page's content.
        $style = new Style();
        $style -> addContent('body { padding-top: 56px; }');
        $page -> addHeadContent($style);

        $page -> addContent(new MainNavigation());

        return $page;
    }
}
