<?php

declare(strict_types=1);

/**
 * The Bootstrap stylesheet, served from this site rather than a CDN. Vendored
 * so the Content Security Policy (see HTMLDocument) can say styles only ever
 * come from here, and so pages keep working with no third party up or
 * reachable.
 */
class BootstrapLink extends LinkTag
{
    public function __construct()
    {
        parent::__construct();

        $this -> rel = 'stylesheet';
        $this -> href = ServerURL::absolute('/bootstrap.min.css');
    }
}
