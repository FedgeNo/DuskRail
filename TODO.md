# TODO

## Headless browser for JS-challenge pages (Cloudflare, etc.)

Some sites serve a JS-based bot challenge ("Just a moment...", Cloudflare's
Turnstile/JS challenge, similar from other WAF vendors) instead of the real
page. `HTTPConnection` is plain cURL with no JavaScript engine, so it can
never resolve one of these - there's no HTTP-level trick around it, since
that's the whole point of the challenge.

Planned architecture (not yet built):

- `HTTPConnection` keeps making the actual network request exactly as it does
  now - the browser never fetches the page itself, and never touches the
  network directly. Our own crawler stays the one and only fetcher (so TLS,
  redirects, cookies, timeouts all stay in the existing code).
- The already-fetched HTML gets injected into a headless browser (e.g. via
  `chrome-php/chrome` driving a headless Chrome over the DevTools Protocol).
- The browser evaluates the page's JavaScript against that injected HTML -
  resolving the challenge script, running any client-side rendering - purely
  as an evaluation layer, nothing more.
- The resulting post-JS HTML comes back out of the browser and feeds into
  the normal `HTMLLoader`-based pipeline unchanged, same as any other page.

In short: the browser's job is narrowly "run the JS on HTML we already have,"
never "go fetch this URL." Keeps the existing crawler architecture intact and
adds the browser as a bolt-on evaluation step only for pages that need it.

Until this exists, a page detected as a JS-challenge interstitial (e.g. exact
title "Just a moment...") is marked crawled anyway (so it stops being
retried), keeping whatever title/description it already had from being
discovered as a link rather than overwriting them with the challenge page's
own placeholder metadata. The images/links already extracted from its markup
are kept regardless. Revisit this once headless-browser fetching exists -
these items should get properly recrawled and reprocessed for real content.

## robots.txt enforcement is deliberately simple, not a real parser

`Host::isDisallowed()` enforces robots.txt now (checked before fetching the
item itself, before following each redirect hop, and before inserting a
same-host image/link discovered on the page), but on purpose it's just a
plain prefix match against every `Disallow:` line in the whole file - not a
real robots.txt parser. Known gaps if this ever needs to get more correct:

- No User-agent grouping - a `Disallow` under any user-agent applies, not
  just ours or `*`. More conservative than the spec, not less.
- No wildcard (`*`, `$`) support in Disallow/Allow values - a pattern like
  `/wp-content/*.js$` is just plain text as far as this is concerned, never
  matched as a wildcard.
- No `Allow` support at all, so no Allow-overrides-Disallow precedence
  (e.g. home.cern's actual robots.txt has `Allow: /wp-content/*.css$` carving
  an exception out of the broader `Disallow: /wp-content/` - that Allow is
  simply invisible to this implementation, so the whole `/wp-content/` prefix
  stays blocked including the stylesheets it meant to exempt).
