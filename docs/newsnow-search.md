# NewsNow Search

`php bin/search-newsnow.php 'trump farmers' 10` and the authenticated
`api/sourceledger-search.php` endpoint with `engine=newsnow` use NewsNow's
English headline search. The adapter remains available through the endpoint,
but SourceLedger currently uses Marginalia for news research.

The parser preserves headline, publisher, displayed time, publication timestamp,
rank and NewsNow's article redirect URL. NewsNow does not supply article excerpts
in this view: `content` contains publisher/time, not an invented excerpt. Selected
results go through DuskRail's ordinary hardened evidence retrieval and redirects.
Topic recommendations are excluded from the matching-headline results.

All browser searches share `var/search-throttle`. An exclusive lock covers the
entire search, including browser cleanup. The completion time is recorded even
on failure; the next search waits at least two seconds from that time. Engines,
users and outbound IPs do not get separate budgets. A caller waits at most ten
seconds for the lock before receiving an explicit busy error, preventing an
unbounded backlog. Page/evidence retrieval itself is not search-throttled.

The state file must be writable by authorized search callers, using the same
service-account ownership and ACL scheme as the browser rotation state.
