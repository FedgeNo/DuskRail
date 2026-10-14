# SourceLedger Retrieval Seam

DuskRail exposes an authenticated retrieval submission endpoint for
SourceLedger:

`POST /api/sourceledger-retrieval`

The caller sends the shared server-to-server credential as:

`Authorization: Bearer <SOURCELEDGER_TOKEN>`

The request body is form-encoded and contains one field:

`url`

Accepted URLs pass DuskRail's normal URL parser and public-DNS resolution
checks before they are inserted into `Items`. The endpoint returns `202` with
the queued `itemId`, normalized URL, and `priority: interactive`.

Interactive items are selected ahead of ordinary crawl work. They do not use
ordinary host politeness reservations or failure backoff, but they still pass
robots-policy checks, public-address checks, worker claims, redirect safety,
and the crawler's normal resource limits. Once crawled, their priority resets
to ordinary work.

Responses use `401` for a missing or invalid shared credential, `400` for an
invalid URL, and `422` when the hostname does not resolve to a public address.
