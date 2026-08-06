<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

Auth::logOut();

header('Location: ' . ServerURL::absolute('/login.php'));
