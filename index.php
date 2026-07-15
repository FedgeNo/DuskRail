<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

$query = trim((string) ($_GET['q'] ?? ''));

$page = Page::create($query !== '' ? $query : 'Home');

$style = new Style();
$style -> addContent('
.search-page {
    max-width: 700px;
    margin: 3rem auto;
    padding: 0 1rem;
}
.result {
    display: flex;
    gap: 1rem;
    padding: 1rem 0;
    border-bottom: 1px solid #333;
}
.result img {
    max-height: 90px;
    max-width: 120px;
    object-fit: cover;
    flex-shrink: 0;
}
.result .url {
    color: #7fbf7f;
    font-size: 0.85rem;
}
.result .description {
    color: #adb5bd;
}
#status {
    color: #adb5bd;
}
');
$page -> addHeadContent($style);

$container = new Div();
$container -> class = 'search-page';

$searchBar = new Div();
$searchBar -> class = 'd-flex gap-2';

$queryInput = new Input();
$queryInput -> id = 'query-input';
$queryInput -> type = 'text';
$queryInput -> value = $query;
$queryInput -> placeholder = 'Search DuskRail...';
$queryInput -> class = 'form-control';
$searchBar -> addContent($queryInput);

$searchButton = new Button('Search');
$searchButton -> id = 'search-button';
$searchButton -> type = 'button';
$searchButton -> class = 'btn btn-primary flex-shrink-0';
$searchBar -> addContent($searchButton);

$container -> addContent($searchBar);

$status = new Div();
$status -> id = 'status';
$status -> class = 'mt-3';
$container -> addContent($status);

$results = new Div();
$results -> id = 'results';
$container -> addContent($results);

$page -> addContent($container);

$script = new Script();
$script -> src = ServerURL::absolute('/search.js');
$page -> addContent($script);

$page -> send();
