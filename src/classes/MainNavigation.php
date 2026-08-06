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

        // Searching is public; running the crawl isn't. So a signed-in
        // operator gets the crawl controls and a way out, and everyone else
        // gets the way in - without which the login page would be reachable
        // only by knowing to type its URL, now that no page redirects there.
        if (Auth::isAuthenticated()) {
            $watch = new Anchor(ServerURL::absolute('/watch.php'), 'Watch');
            $watch -> class = 'NavLink';
            $container -> addContent($watch);

            $signOut = new Anchor(ServerURL::absolute('/logout.php'), 'Sign Out');
            $signOut -> class = 'NavLink';
            $container -> addContent($signOut);
        } else {
            $signIn = new Anchor(ServerURL::absolute('/login.php'), 'Sign In');
            $signIn -> class = 'NavLink';
            $container -> addContent($signIn);
        }

        $this -> addContent($container);

        return parent::toDOM();
    }
}
