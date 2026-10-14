<?php

declare(strict_types=1);

$html = <<<'HTML'
<section><h2><a href="?search=x&amp;searchheadlines=1">Headlines matching: x</a></h2>
<article class="article-card"><a class="article-card__headline" href="https://c.newsnow.com/A/123?-:">Farmers &amp; trade</a><span class="article-publisher__name">Publisher</span><span data-timestamp="1704067200">2h</span></article>
<article class="article-card"><a class="article-card__headline" href="https://c.newsnow.com/A/123?-:">Duplicate</a></article>
<article class="article-card"><a class="article-card__headline" href="javascript:alert(1)">Unsafe</a></article>
<article hidden class="article-card"><a class="article-card__headline" href="https://c.newsnow.com/A/456">Hidden</a></article>
</section><section><h2><a href="/us/Business/Agriculture">Agriculture</a></h2><article class="article-card"><a class="article-card__headline" href="https://c.newsnow.com/A/789">Unrelated topic</a></article></section>
HTML;
$parsed = NewsNowResults::parse($html);
assert_same('NewsNow matching headlines only', 1, count($parsed['results']));
assert_same('NewsNow entities', 'Farmers & trade', $parsed['results'][0]['title']);
assert_same('NewsNow publisher and time', 'Publisher 2h', $parsed['results'][0]['content']);
assert_same('NewsNow publication date', '2024-01-01T00:00:00+00:00', $parsed['results'][0]['publishedDate']);
assert_same('NewsNow preserves redirect URL', 'https://c.newsnow.com/A/123?-:', $parsed['results'][0]['url']);
assert_same('NewsNow unknown markup is an error', 'parse_error', NewsNowResults::parse('<p>Unknown</p>')['status']);
assert_same('NewsNow challenge', 'blocked', NewsNowResults::parse('<title>Just a moment</title>')['status']);
assert_false('NewsNow hostname boundary', NewsNowSearch::isEngineURL('https://newsnow.com.evil.test/'));
$method = new ReflectionMethod(NewsNowSearch::class, 'searchURL');
parse_str(parse_url($method -> invoke(null, 'trump farmers'), PHP_URL_QUERY), $params);
assert_same('NewsNow full query preserved', 'trump farmers', $params['search']);
assert_same('NewsNow requests headline search', '1', $params['searchheadlines']);

$empty = '<title>NewsNow: Vancouver cocaine headlines | Every Source</title><div>No articles were found matching Vancouver cocaine in the headline. Please try searching again.</div>';
assert_same('NewsNow explicit empty search', 'no_results', NewsNowResults::parse($empty)['status']);
assert_same('NewsNow generic 404 stays unrecognized', 'parse_error', NewsNowResults::parse('<title>Not Found</title><h1>404 Not Found</h1>')['status']);
