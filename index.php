<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

$query = trim((string) ($_GET['q'] ?? ''));

$page = Page::create($query !== '' ? $query : 'Home');

$style = new Style();
$style -> addContent('
body {
    background: #000;
    color: #e9ecef;
}
.search-page {
    max-width: 700px;
    margin: 3rem auto;
    padding: 0 1rem;
}
#query-input {
    background: #1a1a1a;
    border-color: #495057;
    color: #e9ecef;
}
#query-input:focus {
    background: #1a1a1a;
    border-color: #7fbf7f;
    color: #e9ecef;
    box-shadow: 0 0 0 0.25rem rgba(127, 191, 127, 0.25);
}
.result {
    display: flex;
    gap: 1rem;
    padding: 1rem 0;
    border-bottom: 1px solid #333;
    color: inherit;
    text-decoration: none;
}
.result:hover .title {
    text-decoration: underline;
}
.result img {
    max-height: 90px;
    max-width: 120px;
    object-fit: cover;
    flex-shrink: 0;
}
.result .title {
    color: #7fbf7f;
}
.result .url {
    font-size: 0.85rem;
    color: #7fbf7f;
}
.image-grid {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.image-grid-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.image-tile {
    display: block;
    flex-shrink: 0;
    color: inherit;
    text-decoration: none;
}
.image-tile img {
    display: block;
    border-radius: 4px;
}
.image-tile .url {
    margin-top: 0.35rem;
    font-size: 0.75rem;
    color: #7fbf7f;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.image-tile .description {
    font-size: 0.75rem;
    color: inherit;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
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

$typeChoice = new Div();
$typeChoice -> class = 'mt-2 d-flex gap-3';

$htmlOption = new Div();
$htmlOption -> class = 'form-check form-check-inline';
$htmlRadio = new Input();
$htmlRadio -> id = 'type-html';
$htmlRadio -> type = 'radio';
$htmlRadio -> name = 'result-type';
$htmlRadio -> value = 'html';
$htmlRadio -> checked = true;
$htmlRadio -> class = 'form-check-input';
$htmlOption -> addContent($htmlRadio);
$htmlLabel = new Label();
$htmlLabel -> for = 'type-html';
$htmlLabel -> class = 'form-check-label';
$htmlLabel -> addContent('Pages');
$htmlOption -> addContent($htmlLabel);
$typeChoice -> addContent($htmlOption);

$imageOption = new Div();
$imageOption -> class = 'form-check form-check-inline';
$imageRadio = new Input();
$imageRadio -> id = 'type-image';
$imageRadio -> type = 'radio';
$imageRadio -> name = 'result-type';
$imageRadio -> value = 'image';
$imageRadio -> class = 'form-check-input';
$imageOption -> addContent($imageRadio);
$imageLabel = new Label();
$imageLabel -> for = 'type-image';
$imageLabel -> class = 'form-check-label';
$imageLabel -> addContent('Images');
$imageOption -> addContent($imageLabel);
$typeChoice -> addContent($imageOption);

$container -> addContent($typeChoice);

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
