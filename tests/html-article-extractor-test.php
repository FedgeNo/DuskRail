<?php

declare(strict_types=1);

assert_same('article extraction removes non-rendered content', 'Visible text', HTMLArticleExtractor::extract(
    '<html><head><title>Metadata</title></head><body>'
    . '<script>secret script</script><script>second script</script><noscript>fallback</noscript><style>styles</style>'
    . '<template>template</template><iframe>frame fallback</iframe><canvas>canvas fallback</canvas>'
    . '<object>object fallback</object><audio>audio fallback</audio><video>video fallback</video>'
    . '<div hidden>hidden text</div><div style="display:none !important">hidden CSS</div>'
    . '<p>Visible text</p></body></html>'
));

assert_same('article extraction removes semantic page chrome', 'Article text', HTMLArticleExtractor::extract(
    '<body><header>Site header</header><nav>Menu<nav>Nested menu</nav></nav><aside>Sidebar</aside><footer>Footer</footer>'
    . '<div role="navigation">Role menu</div><div role="complementary">Role sidebar</div>'
    . '<div role="contentinfo">Role footer</div><div role="banner">Role header</div>'
    . '<div role="search">Search box</div><div role="toolbar">Toolbar</div>'
    . '<main><article><p>Article text</p></article></main></body>'
));

assert_same('article title and block boundaries survive cleanup', "Title\n\nFirst paragraph\n\nSecond paragraph", HTMLArticleExtractor::extract(
    '<body><article><header><h1>Title</h1></header><p>First paragraph</p><p>Second paragraph</p></article></body>'
));
assert_same('article extraction preserves inline text and decodes entities', 'Café & tea', HTMLArticleExtractor::extract('<body><p>Caf&eacute; &amp; <strong>tea</strong></p></body>'));
assert_same('article extraction reuses declared charset decoding', 'café', HTMLArticleExtractor::extract('<body>caf' . chr(0xE9) . '</body>', 'ISO-8859-1'));
assert_same('article extraction accepts HTML fragments', 'A fragment', HTMLArticleExtractor::extract('<p>A fragment</p>'));
assert_same('article extraction returns empty text for an empty document', '', HTMLArticleExtractor::extract(''));
assert_same('ARIA hiding alone does not remove visually present text', 'Visible decoration', HTMLArticleExtractor::extract('<body><span aria-hidden="true">Visible decoration</span></body>'));
assert_same('inline display overrides are respected', 'Visible override', HTMLArticleExtractor::extract('<body><span style="display:none; display:block">Visible override</span></body>'));
