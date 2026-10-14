# Marginalia Browser Search

Run `php bin/search-marginalia.php 'World Health Organization air quality' 10`.
The existing bearer-authenticated `api/sourceledger-search.php` endpoint also
accepts `engine=marginalia`, `query`, and `limit` as POST fields.

The adapter uses the shared Chromium browser, an isolated tab, public-address
validation, engine-domain request restrictions, a per-engine concurrency lock
and the shared 30-second timeout. It returns organic titles, URLs, snippets,
positions and engine names. Related links, navigation and duplicate URLs are
excluded. Unknown markup is an error rather than a successful empty result.
SourceLedger uses this endpoint for news and other non-encyclopedic claim searches.
All engines share the global search lock and two-second post-completion cooldown
documented in newsnow-search.md. A dedicated on-demand
PHP-FPM pool runs this endpoint as the crawler service account so it can access
the private browser endpoint and rotation state. Route only this authenticated
endpoint to that pool; other web endpoints retain their existing worker identity.
Set PHP_CLI_BINARY in the ignored .env if the CLI binary is not PHP_BINDIR/php.
The search worker reuses the crawler's Chromium instance rather than launching
another browser. The configured outbound address rotates for each page context.

Selectors were checked against rendered live HTML. On 2026-09-22, ten different
queries with a one-second pause after each completed search all returned HTTP
200 and ten results. Requests took 1.658–4.912 seconds, averaging 2.971 seconds;
the complete run took 38.713 seconds. No throttling was observed in this small
sequential sample; it does not establish a sustained quota or one-request-per-
second capacity. Results addressed the full queries, but source quality and
ranking varied, including tangential and unreliable sources alongside research
institutions. Evidence assessment still needs to evaluate each source.
