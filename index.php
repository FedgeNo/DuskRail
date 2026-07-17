<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

$query = trim((string) ($_GET['q'] ?? ''));

$page = Page::create($query !== '' ? $query : 'Home');

$layout = new Div();
$layout -> class = 'layout';

$container = new Div();
$container -> class = 'search-page';

$form = new Form();
$form -> id = 'search-form';
// GET to this same page. search.js intercepts submit to run the AJAX flow;
// keeping it a real form means Enter and the button both work through normal
// form submission (and a no-JS submit still lands on ?q=... rather than doing
// nothing at all).
$form -> method = 'get';

// The query box and the Search button sit inline on one row, button to the
// right. The button is a command, not a search parameter, so it stays out of
// the fieldset below.
$searchRow = new Div();
$searchRow -> class = 'd-flex gap-2';

$queryInput = new Input();
$queryInput -> id = 'query-input';
$queryInput -> type = 'text';
$queryInput -> name = 'q';
$queryInput -> value = $query;
$queryInput -> placeholder = 'Search DuskRail...';
$queryInput -> class = 'form-control';
$searchRow -> addContent($queryInput);

$searchButton = new Button('Search');
$searchButton -> id = 'search-button';
$searchButton -> type = 'submit';
$searchButton -> class = 'btn btn-primary flex-shrink-0';
$searchRow -> addContent($searchButton);

$form -> addContent($searchRow);

// A fieldset just for the Pages/Images radios - its legend is what
// disambiguates them (on their own, "Pages"/"Images" don't say pages/images
// of what). Giving a radio group that shared label is exactly what a
// fieldset + legend is for.
$fieldset = new Fieldset();
$fieldset -> class = 'result-type mt-2';
$fieldset -> addContent(new Legend('Result type'));

$typeChoice = new Div();
$typeChoice -> class = 'd-flex gap-3';

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

$fieldset -> addContent($typeChoice);

$form -> addContent($fieldset);

$container -> addContent($form);

$status = new Div();
$status -> id = 'status';
$status -> class = 'mt-3';
$container -> addContent($status);

$results = new Div();
$results -> id = 'results';
$container -> addContent($results);

$layout -> addContent($container);

// Populated by search.js when an image result is clicked - placeholder text
// server-rendered here rather than left truly empty, since
// HTMLObject::fillEmptyNonVoidTags() would inject an empty text node into a
// genuinely-empty div anyway (defeating a CSS :empty-based placeholder).
$preview = new Div();
$preview -> id = 'preview';
$preview -> class = 'preview-column';
$preview -> addContent('Select an image to preview it here.');
$layout -> addContent($preview);

$page -> addContent($layout);

$script = new Script();
$script -> src = ServerURL::absolute('/search.js');
$page -> addContent($script);

$page -> send();
