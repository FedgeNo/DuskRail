<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

Auth::requirePage();

$page = Page::create('Watching the Crawl');
$watch = new Div();
$watch -> class = 'WatchPage';

// The instrumentation - status line, topic control, seed box - is its own
// scroll region above the live feed, so either area can move without moving
// the other.
$controls = new Div();
$controls -> class = 'WatchControls';

$controls -> addContent(new CrawlStatisticsTable());

// Says running/stopped and the last hour's throughput - filled in by
// watch.js from api/crawler-status.php, since only the crawler manager's
// heartbeat knows, and it changes while the page is open.
$crawlerStatus = new Div();
$crawlerStatus -> id = 'crawler-status';
$crawlerStatus -> class = 'CrawlerStatus p-2';
$crawlerStatus -> attributes['aria-live'] = 'polite';
$controls -> addContent($crawlerStatus);

$topicBar = new Div();
$topicBar -> class = 'd-flex gap-2 p-2 bg-secondary';

$topicInput = new Input();
$topicInput -> id = 'topic-input';
$topicInput -> type = 'text';
// Prefilled with the topic actually in force - an empty box over an active
// focused crawl misreads as "no topic set".
$topicInput -> value = Setting::value(CRAWL_TOPIC_SETTING) ?? '';
$topicInput -> placeholder = 'Focus the crawl on a topic (e.g. "physics")…';
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

$controls -> addContent($topicBar);

$seedBar = new Div();
$seedBar -> class = 'd-flex gap-2 p-2 bg-secondary';

$seedInput = new Input();
$seedInput -> id = 'seed-input';
$seedInput -> type = 'text';
$seedInput -> placeholder = 'Add a URL to the crawl queue…';
$seedInput -> class = 'form-control';
$seedBar -> addContent($seedInput);

$seedAddButton = new Button('Add Seed');
$seedAddButton -> id = 'seed-add';
$seedAddButton -> type = 'button';
$seedAddButton -> class = 'btn btn-primary flex-shrink-0';
$seedBar -> addContent($seedAddButton);

$controls -> addContent($seedBar);
$watch -> addContent($controls);

$feed = new Div();
$feed -> id = 'feed';
$feed -> class = 'bg-dark text-light font-monospace p-3';
$watch -> addContent($feed);
$page -> addContent($watch);

$script = new Script();
$script -> src = ServerURL::absolute('/watch.js');
$page -> addContent($script);

$page -> send();
