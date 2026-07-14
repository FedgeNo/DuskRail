<?php

declare(strict_types=1);

class BootstrapLink extends LinkTag
{
    public function __construct()
    {
        parent::__construct();

        $this -> rel = 'stylesheet';
        $this -> href = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css';
    }
}
