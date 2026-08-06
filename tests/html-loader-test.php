<?php

declare(strict_types=1);

$pageURL = new URL('https://example.com/dir/page.html');

// Charset: BOM beats everything, header beats meta, meta beats the default.
$bom = chr(0xEF) . chr(0xBB) . chr(0xBF) . '<html><body>caf&#233;</body></html>';
assert_same('BOM page reads clean', 'café', HTMLLoader::extractBodyText(HTMLLoader::load($bom, null)));

$latin = '<html><head><meta charset="iso-8859-1"></head><body>caf' . chr(0xE9) . '</body></html>';
assert_same('meta charset sniffed when no header', 'café', HTMLLoader::extractBodyText(HTMLLoader::load($latin, null)));
assert_same('header charset wins over sniffing', 'café', HTMLLoader::extractBodyText(HTMLLoader::load('<html><body>caf' . chr(0xE9) . '</body></html>', 'iso-8859-1')));

$httpEquiv = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"></head><body>caf' . chr(0xE9) . '</body></html>';
assert_same('http-equiv form sniffs too', 'café', HTMLLoader::extractBodyText(HTMLLoader::load($httpEquiv, null)));

// Metadata: classic tags first, then fallbacks.
$meta = HTMLLoader::load('<html><head><title>Real Title</title><meta name="description" content="Real description."></head><body></body></html>', null);
$extracted = HTMLLoader::extractMetadata($meta);
assert_same('title extracted', 'Real Title', $extracted['title']);
assert_same('description extracted', 'Real description.', $extracted['description']);

$og = HTMLLoader::load('<html><head><meta property="og:title" content="OG Title"></head><body></body></html>', null);
assert_same('og:title fallback', 'OG Title', HTMLLoader::extractMetadata($og)['title']);

// Base href changes what links resolve against.
$based = HTMLLoader::load('<html><head><base href="/en/"></head><body><a href="page">x</a></body></html>', null);
$baseURL = HTMLLoader::baseURL($based, $pageURL);
assert_same('base href resolves links', 'https://example.com/en/page', HTMLLoader::extractAnchorLinks($based, $baseURL)[0]['url'] -> toString());

// nofollow anchors are dropped; plain ones survive.
$links = HTMLLoader::load('<html><body><a href="/a">a</a><a href="/b" rel="nofollow">b</a><a href="/c" rel="ugc">c</a><a href="/d" rel="sponsored noopener">d</a></body></html>', null);
$found = HTMLLoader::extractAnchorLinks($links, $pageURL);
assert_same('nofollow/ugc/sponsored dropped', 1, count($found));
assert_same('the plain link survives', 'https://example.com/a', $found[0]['url'] -> toString());

// Robots directives.
$robots = HTMLLoader::load('<html><head><meta name="robots" content="noindex, nofollow"></head><body></body></html>', null);
$directives = HTMLLoader::robotsDirectives($robots);
assert_true('meta noindex read', $directives['noindex']);
assert_true('meta nofollow read', $directives['nofollow']);

$none = HTMLLoader::robotsDirectives(HTMLLoader::load('<html><head><meta name="robots" content="none"></head><body></body></html>', null));
assert_true('none means noindex', $none['noindex']);
assert_true('none means nofollow', $none['nofollow']);

$absent = HTMLLoader::robotsDirectives(HTMLLoader::load('<html><body></body></html>', null));
assert_false('absent meta means indexable', $absent['noindex']);

// Canonical.
$canonical = HTMLLoader::load('<html><head><link rel="canonical" href="https://example.com/real"></head><body></body></html>', null);
assert_same('canonical extracted', 'https://example.com/real', HTMLLoader::canonicalURL($canonical, $pageURL) ?-> toString());
assert_same('no canonical means null', null, HTMLLoader::canonicalURL(HTMLLoader::load('<html><body></body></html>', null), $pageURL));

// Boilerplate: exact-token class matching protects real content.
$boilerplate = HTMLLoader::load('<html><body><div class="page-header"><h1>Real Heading</h1></div><div class="header">chrome</div><p>Body text.</p></body></html>', null);
HTMLLoader::removeBoilerplateElements($boilerplate);
$text = HTMLLoader::extractBodyText($boilerplate);
assert_true('exact token "header" removed', !str_contains($text, 'chrome'));
assert_true('compound "page-header" kept', str_contains($text, 'Real Heading'));

// Block boundaries become whitespace before text is read - minified markup
// carries none of its own, and textContent alone glues "TitleFirst" together.
$minified = HTMLLoader::load('<html><body><h1>Title</h1><p>First para.</p><p>Second para.</p><ul><li>one</li><li>two</li></ul><div>after<br>break</div></body></html>', null);
HTMLLoader::separateBlockElements($minified);
$separated = HTMLLoader::extractBodyText($minified);
assert_false('no glued junctions remain', str_contains($separated, 'TitleFirst') || str_contains($separated, 'onetwo'));
assert_true('paragraphs break apart', str_contains($separated, 'First para.') && str_contains($separated, 'Second para.'));
assert_true('br becomes a line break', str_contains($separated, 'after' . chr(10)) && str_contains($separated, 'break'));

$cells = HTMLLoader::load('<html><body><table><tr><td>alpha</td><td>beta</td></tr></table></body></html>', null);
HTMLLoader::separateBlockElements($cells);
assert_true('table cells separate with a space', str_contains(HTMLLoader::extractBodyText($cells), 'alpha beta'));

// Images separate their neighbors - with alt text expanded inside them, and
// even without any alt at all.
$imgGlue = HTMLLoader::load('<html><body><div>photo<img src="/x.jpg" alt="a cat">caption</div><div>left<img src="/y.jpg">right</div></body></html>', null);
HTMLLoader::separateBlockElements($imgGlue);
HTMLLoader::inlineImageAltText($imgGlue);
$imgText = HTMLLoader::extractBodyText($imgGlue);
assert_true('alt text spaced from both neighbors', str_contains($imgText, 'photo a cat caption'));
assert_true('alt-less image still separates words', str_contains($imgText, 'left right'));

// An image as the last child has no nextSibling - insertBefore takes null
// there and appends, per the DOM contract. Pinned because it reads like it
// should throw.
$trailing = HTMLLoader::load('<html><body><p>trailing<img src="/x.jpg" alt="end cap"></p></body></html>', null);
HTMLLoader::separateBlockElements($trailing);
HTMLLoader::inlineImageAltText($trailing);
assert_same('trailing image spaces cleanly', 'trailing end cap', HTMLLoader::extractBodyText($trailing));

// Descriptions read from parent text benefit too.
$gluedLinks = HTMLLoader::load('<html><body><div><h3>Heading</h3><a href="/a">link text</a></div></body></html>', null);
HTMLLoader::separateBlockElements($gluedLinks);
$linkDescription = HTMLLoader::extractAnchorLinks($gluedLinks, $pageURL)[0]['description'];
assert_false('link description not glued to the heading', str_contains($linkDescription, 'Headinglink'));

// Alt text rides into body text.
$alt = HTMLLoader::load('<html><body><img src="/x.jpg" alt="A chart of results"><p>After.</p></body></html>', null);
HTMLLoader::inlineImageAltText($alt);
assert_true('alt text inlined', str_contains(HTMLLoader::extractBodyText($alt), 'A chart of results'));
