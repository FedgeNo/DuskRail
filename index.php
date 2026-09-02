<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

// Issued here rather than waiting for the first search, so the browser
// already holds its token when it starts calling api/search.php. Without
// this, the cookie arrives on the response to the first search and every
// reload before it lands counts as a different client.
RateLimit::issueClientToken();

$query = trim((string) ($_GET['q'] ?? ''));

// Which of the two result types is selected, honoured server-side so a
// submit without JS (or a shared/bookmarked ?result-type=image URL) comes
// back with the radio the reader actually chose rather than silently
// resetting to Pages.
$resultType = ($_GET['result-type'] ?? 'html') === 'image' ? 'image' : 'html';

$page = Page::create($query !== '' ? $query : 'Home');

$layout = new Div();
$layout -> class = 'Layout';

$container = new Div();
$container -> class = 'SearchPage';

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
$queryInput -> attributes['maxlength'] = (string) SearchResults::MAX_QUERY_LENGTH;
$queryInput -> value = $query;
$queryInput -> placeholder = 'Search DuskRail…';
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
$fieldset -> class = 'ResultType mt-2';
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
$htmlRadio -> checked = $resultType === 'html';
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
$imageRadio -> checked = $resultType === 'image';
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

// Always rendered, shown only when no search is in play - once a query is
// live, the result count in #status is the number that matters, and
// search.js flips this back on when the query is cleared rather than the
// page needing a reload to say what's searchable.
$indexStats = new IndexStats();
$indexStats -> id = 'index-stats';

if ($query !== '') {
    $indexStats -> attributes['hidden'] = 'hidden';
}

$container -> addContent($indexStats);

$status = new Div();
$status -> id = 'status';
$status -> class = 'mt-3';
// Announced to screen readers when it changes - the result count and error
// messages land here, and nothing else on the page says them out loud.
$status -> attributes['aria-live'] = 'polite';
$container -> addContent($status);

$results = new Div();
$results -> id = 'results';
$container -> addContent($results);

$layout -> addContent($container);

// Populated by search.js: the placeholder prompt once there are image
// results to preview, then the selected image's own preview content.
$preview = new Div();
$preview -> id = 'preview';
$preview -> class = 'PreviewColumn';
$layout -> addContent($preview);

$page -> addContent($layout);

$script = new Script();
$script -> src = ServerURL::absolute('/search.js');
$page -> addContent($script);

$page -> send();
