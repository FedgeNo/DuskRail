<?php

declare(strict_types=1);

$html = <<<'HTML'
<title>Search</title><main>
<div><div><h2><a href="https://www.who.int/">WHO &amp; air quality</a></h2></div><div><p>Air <b>quality</b> guidance.</p></div></div>
<div><h2><a href="//example.org/">Référence</a></h2><p>Second excerpt.</p><a href="https://other.example.org/">Related page</a></div>
<div><h2><a href="https://www.who.int/">Duplicate</a></h2><p>Duplicate snippet.</p></div>
<div><h2><a href="javascript:alert(1)">Invalid</a></h2></div>
<div hidden><h2><a href="https://hidden.example.com/">Hidden</a></h2></div>
<h2><a href="/settings">Search Settings</a></h2>
</main><footer><h2><a href="https://footer.example.com/">Footer</a></h2></footer>
HTML;
$parsed = MarginaliaResults::parse($html);
assert_same('Marginalia parses results', 'ok', $parsed['status']);
assert_same('Marginalia excludes duplicate, hidden, unsafe, related and navigation links', 2, count($parsed['results']));
assert_same('Marginalia title entities', 'WHO & air quality', $parsed['results'][0]['title']);
assert_same('Marginalia nested excerpt', 'Air quality guidance.', $parsed['results'][0]['content']);
assert_same('Marginalia Unicode title', 'Référence', $parsed['results'][1]['title']);
assert_same('Marginalia second excerpt', 'Second excerpt.', $parsed['results'][1]['content']);
assert_same('Marginalia protocol relative URL', 'https://example.org/', $parsed['results'][1]['url']);
assert_same('Marginalia positions', [1, 2], array_column($parsed['results'], 'position'));
assert_same('Marginalia limit', 1, count(MarginaliaResults::parse($html, 1)['results']));
assert_same('Marginalia unfamiliar page', 'parse_error', MarginaliaResults::parse('<p>Unknown</p>')['status']);
assert_same('Marginalia rate limit', 'blocked', MarginaliaResults::parse('<title>Too many requests</title>')['status']);
assert_same('Marginalia credentials rejected', null, MarginaliaResults::resultURL('https://user:password@example.com/'));
assert_same('Marginalia search links rejected', null, MarginaliaResults::resultURL('https://marginalia-search.com/search?query=test'));
assert_true('Marginalia engine allowed', MarginaliaSearch::isEngineURL('https://marginalia-search.com/search?query=test'));
foreach (['https://marginalia-search.com.evil.com/', 'https://evilmarginalia-search.com/', 'http://marginalia-search.com/', 'https://marginalia-search.com:8080/', 'https://user@marginalia-search.com/'] as $url) {
    assert_false('Marginalia unsafe request rejected: ' . $url, MarginaliaSearch::isEngineURL($url));
}
$query = 'World Health Organization air quality';
$method = new ReflectionMethod(MarginaliaSearch::class, 'searchURL');
parse_str(parse_url($method -> invoke(null, $query), PHP_URL_QUERY), $parameters);
assert_same('Marginalia full query preserved', $query, $parameters['query']);
try {
    MarginaliaSearch::search('', 10);
    assert_true('Marginalia validates before connecting', false);
} catch (InvalidArgumentException $exception) {
    assert_true('Marginalia validation explanation', $exception -> getMessage() !== '');
}
