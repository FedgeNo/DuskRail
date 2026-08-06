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

        // Read by watch.js and sent back as X-CSRF-Token on every
        // state-changing request (see Auth::requireWriteAPI()). Only rendered
        // for a session that has one - minting a token for an anonymous
        // visitor would just start a session for every hit on the login page.
        if (Auth::isAuthenticated()) {
            $csrfToken = new Meta();
            $csrfToken -> name = 'csrf-token';
            $csrfToken -> content = Auth::csrfToken();
            $page -> addHeadContent($csrfToken);
        }

        $page -> addHeadContent(new Title($title . ' - ' . $config['siteTitle']));

        $favicon = new LinkTag();
        $favicon -> rel = 'icon';
        $favicon -> type = 'image/svg+xml';
        $favicon -> href = ServerURL::absolute('/favicon.svg');
        $page -> addHeadContent($favicon);

        $page -> addHeadContent(new BootstrapLink());

        $stylesheet = new LinkTag();
        $stylesheet -> rel = 'stylesheet';
        $stylesheet -> href = ServerURL::absolute('/style.css');
        $page -> addHeadContent($stylesheet);

        $page -> addContent(new MainNavigation());

        return $page;
    }
}
