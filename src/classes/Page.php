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

        $title_element = new Title();
        $title_element -> contents[] = $title . ' - ' . $config['siteTitle'];
        $page -> addHeadContent($title_element);

        $bootstrap = new Link();
        $bootstrap -> rel = 'stylesheet';
        $bootstrap -> href = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
        $page -> addHeadContent($bootstrap);

        $page -> addContent(new MainNavigation());

        return $page;
    }
}
