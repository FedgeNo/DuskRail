<?php

declare(strict_types=1);

// Use an isolated browser context without reading deployment configuration
// or changing the crawler's outbound-address rotation files.
final class Env {
    public static function get(string $key, string $default = ''): string {
        return $default;
    }
}

require __DIR__ . '/../../init.php';

if (empty($argv[1])) {
    throw new RuntimeException('Supply a running Chrome DevTools host:port.');
}

$html = <<<'HTML'
<!doctype html><html><head>
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'">
<title>Rendering Fixture</title></head><body><div id="content"></div>
<script>
window.addEventListener('load', function () {
    setTimeout(function () {
        const container = document.getElementById('content');
        container.appendChild(document.createTextNode('Delayed caption'));
        const image = document.createElement('img');
        image.setAttribute('src', '/delayed.jpg');
        container.appendChild(image);
        const metadata = document.createElement('script');
        metadata.setAttribute('type', 'application/ld+json');
        metadata.textContent = JSON.stringify({description: 'Delayed metadata'});
        document.head.appendChild(metadata);
    }, 1500);
});
</script></body></html>
HTML;

$pageURL = new URL('https://example.org/render-fixture');
$started = microtime(true);
$rendered = HeadlessBrowser::render($argv[1], $html, $pageURL);

if ($rendered === null || microtime(true) - $started < 3.0) {
    throw new RuntimeException('Rendering did not complete its settling window.');
}

$document = HTMLLoader::load($rendered, 'UTF-8');
$metadata = HTMLLoader::extractMetadata($document);
HTMLLoader::removeNonContentElements($document);
$images = HTMLLoader::extractImageLinks($document, $pageURL);

if ($metadata['description'] !== 'Delayed metadata'
    || count($images) !== 1
    || $images[0]['description'] !== 'Delayed caption'
    || $images[0]['url'] -> toString() !== 'https://example.org/delayed.jpg') {
    throw new RuntimeException('Delayed content was not extracted after rendering and cleanup.');
}

echo 'Browser render test passed: delayed image, caption and JSON-LD retained after cleanup.' . PHP_EOL;
