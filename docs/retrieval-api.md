# Retrieval API

An authenticated server-to-server API that lets another service search the web
through DuskRail's browser and have specific pages crawled on demand. It is
disabled unless `RETRIEVAL_API_TOKEN` is set in `.env`. Every request sends
that token as:

`Authorization: Bearer <RETRIEVAL_API_TOKEN>`

A missing or invalid token gets `401`.

## Web search

`POST /api/web-search.php` with form fields `engine` (`marginalia`, `newsnow`
or `wikipedia`; default `marginalia`), `query` and `limit`. See
`marginalia-search.md` and `newsnow-search.md` for the engines and the shared
search throttle.

## Queue a page

`POST /api/retrieval.php` with one form field, `url`.

Accepted URLs pass DuskRail's normal URL parser and public-DNS resolution
checks before they are inserted into `Items`. The endpoint returns `202` with
the queued `itemId`, normalized URL, and `priority: interactive`.

Interactive items are selected ahead of ordinary crawl work. They do not use
ordinary host politeness reservations or failure backoff, but they still pass
robots-policy checks, public-address checks, worker claims, redirect safety,
and the crawler's normal resource limits. Once crawled, their priority resets
to ordinary work.

Responses use `400` for an invalid URL and `422` when the hostname does not
resolve to a public address.

## Check progress

`GET /api/retrieval-status.php?itemId=N` returns the item's `state`
(`queued`, `reading`, `available` or `failed`), plus its URL, title, type and
`crawledTime`. An item merged into another by a redirect is still found under
its original ID through `ItemAliases`.

## Read the content

`GET /api/retrieval-content.php?itemId=N` returns the crawled page's
extracted `text` (with `textTruncated`), URL, title, type and `crawledTime`,
or `409` while no content is available yet.
