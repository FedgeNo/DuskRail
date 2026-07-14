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
#feed .description {
    color: #adb5bd;
}
');
$page -> addHeadContent($style);

$topicBar = new Div();
$topicBar -> class = 'd-flex gap-2 p-2 bg-secondary';

$topicInput = new Input();
$topicInput -> id = 'topic-input';
$topicInput -> type = 'text';
$topicInput -> placeholder = 'Focus the crawl on a topic (e.g. "physics")...';
$topicInput -> class = 'form-control';
$topicBar -> addContent($topicInput);

$topicButton = new Button('Set Topic');
$topicButton -> id = 'topic-set';
$topicButton -> type = 'button';
$topicButton -> class = 'btn btn-primary flex-shrink-0';
$topicBar -> addContent($topicButton);

$topicClearButton = new Button('Clear');
$topicClearButton -> id = 'topic-clear';
$topicClearButton -> type = 'button';
$topicClearButton -> class = 'btn btn-outline-light flex-shrink-0';
$topicBar -> addContent($topicClearButton);

$page -> addContent($topicBar);

$feed = new Div();
$feed -> id = 'feed';
$feed -> class = 'bg-dark text-light font-monospace p-3';
$page -> addContent($feed);

$script = new Script();
$script -> src = ServerURL::absolute('/watch.js');
$page -> addContent($script);

$page -> send();
