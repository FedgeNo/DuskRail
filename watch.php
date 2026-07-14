<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

$page = Page::create('Watching the Crawl');

$style = new Style();
$style -> addContent('
#feed {
    height: 80vh;
    overflow-y: auto;
}
#feed .item {
    opacity: 0;
    animation: flash-in 0.4s ease-out forwards;
    border-bottom: 1px solid #333;
    padding: 0.25rem 0;
}
@keyframes flash-in {
    0% { opacity: 0; background-color: #ffd54f; color: #000; }
    100% { opacity: 1; background-color: transparent; }
}
');
$page -> addHeadContent($style);

$feed = new Div();
$feed -> id = 'feed';
$feed -> class = 'bg-dark text-light font-monospace p-3';
$page -> addContent($feed);

$script = new Script();
$script -> src = ServerURL::absolute('/watch.js');
$page -> addContent($script);

$page -> send();
