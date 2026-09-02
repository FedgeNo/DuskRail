# DuskRail

A search engine — web crawler, index, and search interface — built from the
ground up in PHP. No web framework, Elasticsearch, or Composer: a crawler
that fetches and parses the open web, Manticore Search for the derived
full-text index, MariaDB for authoritative data, and a small AJAX front end.

## What it does

- **Crawls** pages starting from URLs you seed, discovering more through the
  links and images on each page and the sites' own sitemaps, fetching through
  a real, shared headless Chrome instance so requests are genuinely
  indistinguishable from an ordinary browser's.
- **Indexes** each page's title, description, and full body text — HTML,
  PDFs, and plain text alike — plus a thumbnail for every image it finds.
  Keywords are retained as metadata but do not influence search ranking.
- **Searches** that index with Manticore ranking, over both pages and images,
  through a web UI with match snippets, infinite scroll, and an image preview
  panel.

## Features

### Crawler

- **Real browser fetching** — every page/image fetch is a genuine navigation
  in a real, shared headless Chrome instance (`ChromeConnection`/`ChromeTab`
  driving Chrome's DevTools Protocol directly, via a hand-rolled minimal
  WebSocket client — no Composer package), not an HTTP client approximating
  one: the TLS handshake, HTTP/2 framing, and every header (including
  `Sec-Fetch-*` and client hints) are Chrome's own, with `Referer` set from a
  real parent page looked up in the link graph rather than guessed. Chrome's
  own "headless" identity is overridden with a plausible desktop one. Only
  robots.txt and sitemap fetches and the internal IANA TLD-list refresh use a
  plain (still Chrome-shaped) HTTP client, since none of those is a crawl of
  a site's content.
- **JS bot-challenge resolution** — a Cloudflare-style "Just a moment..."
  interstitial is handed to that same shared browser, which runs its
  JavaScript for real (its own verification fetch, redirect, reload) and
  hands back the resulting page; the item is still indexed if no challenge
  or headless browser is available, without ever fetching the challenge a
  second time. This is the only path where a crawled page actually executes,
  and it's entered on the page's own say-so (the interstitial is recognized
  by its title), so it's treated as hostile by construction: that tab may
  only reach its own registrable domain or the exact reviewed Cloudflare
  challenge host, downloads are denied, new windows are blocked, and the
  renderer's heap is capped.
- **Concurrent workers, one shared browser** — a supervisor
  (`bin/crawler-manager.php`) runs several single-item worker processes at
  once, each opening its own isolated tab (separate cookies/storage) in one
  persistent Chrome instance rather than paying its ~1-2 second startup cost
  per item; each item is atomically reserved so no two workers ever crawl the
  same one. The shared browser is health-checked continuously and rotated
  roughly hourly — a rotation drains rather than kills, only tearing down the
  outgoing instance once every worker still using it has finished.
- **Politeness** — per-host request cooldowns (longer after a `429`/`503`).
  A host's turn is reserved atomically as its item is claimed, so concurrent
  workers can't each pick a different item on the same host and fire at it
  together.
- **robots.txt** — fetched and cached per host (re-read weekly), enforced
  before every fetch, every redirect hop, and every same-host link discovered
  on a page, against the path *and* query string. Follows Google's documented
  algorithm rather than a plain prefix match: `Allow`/`Disallow` patterns
  (`*` wildcards, trailing `$` end-anchors) scoped to the `User-agent: *`
  group, the longest matching pattern wins, ties go to `Allow`; only the
  first 500 KiB is read, as Google's own parser does. `Crawl-delay` is
  honored (capped at an hour). A robots.txt that can't be read is never
  treated as permission — "no response", a `403` or a `5xx` records the
  host's rules as *unknown* (as distinct from a `404`, which really does
  mean no rules), and its items are left untouched for a later retry rather
  than crawled.
- **Page-level opt-outs** — `<meta name="robots">` and `X-Robots-Tag` are
  honored: `noindex` pages are crawled but never returned by search,
  `nofollow` pages contribute no links, and individually
  `rel="nofollow"`/`ugc`/`sponsored` links are skipped.
- **Sitemaps** — `Sitemap:` lines in robots.txt are ingested (bounded, one
  index level deep) on the same weekly cadence as the rules themselves, so
  pages nothing links to yet still get found.
- **Canonical URLs** — `<link rel="canonical">` pointing elsewhere merges the
  page into its canonical row, link edges included, so print/session/mobile
  variants collapse into one result instead of competing with themselves.
- **Queue shape** — recrawl is adaptive: content is hashed on every crawl,
  unchanged pages double their wait (a week up to two months), changed ones
  reset to a week. A URL the crawler resolved as unusable is remembered so
  rediscovering it doesn't restart the fetch-and-delete cycle; one host can
  only have so many URLs waiting at once, so a single large site can't swamp
  the frontier; and a host that stops answering backs off on an escalating
  schedule (minutes up to a week) with nothing deleted over an outage.
- **Beyond HTML** — PDFs have their text extracted and indexed (via
  `pdftotext`, when installed) and plain-text files are indexed directly;
  page charsets are taken from the BOM, the header, or a sniffed
  `<meta charset>`, in that order, so legacy pages don't index as mojibake.
- **SSRF hardening** — a URL is only crawlable if its host has a real,
  currently-delegated TLD (list fetched from IANA and refreshed weekly),
  which rejects IP literals, `localhost`, and internal-only suffixes like
  `.corp`/`.local` outright. Because a name is not an address, that isn't
  taken as the answer: the host is resolved before the first request, and one
  that points at a loopback, private, link-local (the cloud metadata endpoint
  included), or otherwise reserved address is refused — as is a host that
  resolves to a mix of public and private addresses, since it, not the
  crawler, would pick which one got connected to. Empty DNS answers are also
  refused. The approved answer is pinned to the connection: cURL uses
  `CURLOPT_RESOLVE`, while Chromium uses a crawler-owned loopback SOCKS proxy
  that resolves, validates, and connects atomically without losing TLS SNI or
  certificate verification. `robots.txt` redirects stay on their original
  host, and neither HTTP client follows a redirect on its own.
- **Hostile-input limits** — the site being crawled never gets to decide how
  much work its page costs. Response bodies, decompressed sitemaps, DevTools
  messages, robots.txt rules and wildcards, links taken off one page, and the
  text read to describe each of them are all bounded; `pdftotext` runs under
  a timeout; images are sized from their header before a pixel is decoded;
  and a hostname longer than DNS permits is rejected rather than left for the
  database to reject mid-crawl.
- **Redirects** — followed with a hop cap and loop protection; a redirect onto
  an already-known URL is de-duplicated rather than stored twice.
- **URL canonicalization** — forces HTTPS, strips tracking parameters
  (`utm_*`, `fbclid`, …) and image cache-busters, sorts query parameters, and
  resolves relative links per RFC 3986.
- **Content extraction** — title/description/keywords from classic meta tags,
  Open Graph, Twitter Cards, and JSON-LD; boilerplate (nav/header/footer/ads)
  stripped before body text is captured; the whitespace that rendering would
  imply is injected at block boundaries first, so minified markup doesn't
  extract as glued-together words; image `alt` text folded in.
- **Image handling** — decodes and thumbnails images, rejecting tracking
  pixels, tiny icons, and decompression bombs. SVG, which no image decoder
  reads, is rendered by the shared browser and thumbnailed from that — as a
  picture, never as a document, so nothing in it executes.
- **Self-healing queue** — a page that fails outright is dropped and
  remembered; one that hangs the crawler is retried a bounded number of times
  first. Nothing that couldn't be turned into presentable content is ever
  left in the index.

### Search

- **Full-text ranking** — Manticore ranks a bounded content pool, then results
  are ranked first by how many distinct
  *registrable domains* link to a result using matching anchor text (an
  external relevance signal that ten links from one site can't inflate), then
  by direct content relevance, then by how often the URL is linked at all.
  Domains rather than hostnames, via the Public Suffix List: anyone who owns
  one domain can point unlimited hostnames at it with a single wildcard DNS
  record, so counting hostnames would make it free to manufacture as much
  "independent" endorsement as you liked. Registering a domain is the step
  that costs money, so that's the unit counted. Retrieval is two-stage — link
  ranking runs over a bounded pool of the top matches by content relevance —
  so link reranking costs the same at millions of indexed pages as at
  thousands. Only searchable text and ranking attributes are copied into
  Manticore; result URLs and display fields are hydrated from MariaDB by
  primary key after the final page is selected.
- **Ranking integrity** — every ranking input is written by the page being
  ranked, so each is bounded by what it costs to fake. A page's own domain
  doesn't count toward its link signal (four out of five links in a real
  crawl are a site linking itself, and self-endorsement isn't endorsement).
  The `keywords` meta tag is not indexed — it's the one field no reader ever
  sees, so nothing in it has to be true. The popularity counter counts
  distinct linking *pages*, not mentions, so repeating a link or being
  recrawled adds nothing. The number of times one word is stored for indexing
  remains capped, limiting both stuffing and index growth before Manticore
  tokenizes it.
- **Snippets** — each result shows the text around where the query actually
  matched, with the terms highlighted, rather than the first lines of a
  description that may not contain the match at all.
- **Phrases** — a quoted query (`"particle physics"`) matches as an exact
  phrase.
- **Pages and images** — toggle between page results (HTML, PDF, plain text)
  and an image grid.
- **Infinite scroll** — results paginate in as you scroll; the justified image
  grid lays out each page independently and re-flows on resize.
- **Image preview** — clicking an image opens a Google-Images-style side panel:
  thumbnail first, then the full-resolution image swapped in once it loads,
  with the full description and a link to the page it was found on.
- **Public** — searching needs no account. Running the crawl does: the live
  feed, the focus topic and deleting items sit behind a single operator
  password (`bin/install.php` sets it; only its hash is stored), and the
  endpoints that change something take a CSRF token as well as the session.
- **Rate limited** — the public endpoints carry two per-minute budgets, by IP
  and by client cookie. The IP budget is the real limit; the cookie one is
  much tighter and catches a single client that has gone wrong (a retry loop,
  a page reloading itself) without throttling everyone else behind the same
  address. Over budget answers `429` with `Retry-After`, and the search page
  says how long the wait is rather than reporting a generic failure.
- **Bounded queries** — public search input is capped before it reaches
  Manticore, and invalid oversized input receives a clear `400` response.

### Operations

- **Two service accounts** — the crawler runs as its own system user, not as
  the web server's. Its only writable paths are the configured thumbnail
  directory, the TLD cache, and its own run state. Nothing crosses between
  the two services through the filesystem — they share a database, which is
  where the focused-crawl topic lives, so the web server needs no general
  write access inside the project.
- **Least-privilege database access** — the stored runtime identity has only
  SELECT/INSERT/UPDATE/DELETE. Installation and schema migrations use a
  separately supplied administrator identity that is never persisted.
- **Shared search service** — Manticore is one system-wide service shared by
  applications. DuskRail uses its own credentials, restricted to
  `duskrail_*`, and neither owns nor manages the daemon.
- **Contained services** — crawler and list-refresh units run with a read-only
  system/home view, private devices and temporary storage, no privilege
  escalation, a restrictive umask, and explicitly allowlisted writable paths.
- **Hardened serving** — the site is HTTPS-only (plain HTTP just redirects,
  with HSTS), every page carries a Content Security Policy that only allows
  this origin's own scripts and styles (possible because Bootstrap is served
  locally, not from a CDN), internals (`var/`, `bin/`, `src/`, `.env`) are
  denied at the web server, and the sign-in form is throttled per IP before
  any password is ever checked.
- **`watch.php`** — a live feed of what's being crawled, whether the crawler
  is running and its last hour's throughput, a control to focus the crawl on
  a topic (ranking not-yet-crawled URLs by how on-topic the pages linking to
  them are), and a box to seed new URLs into the queue.
- **`bin/backup.php`** — dumps the database and archives DuskRail's configured
  thumbnail directory, timestamped, keeping the last seven runs.
  `mysqldump` and `gzip` are checked independently and an artifact is accepted
  only when it contains schema.
- **`bin/test.php`** — a dependency-free test suite over the pure logic (URL
  parsing/resolution, robots.txt matching, HTML extraction, address
  classification, public-suffix handling, text handling).
- **`bin/refresh-lists.php`** — downloads the two published lists the project
  depends on but doesn't own: IANA's root-zone TLD list and Mozilla's Public
  Suffix List. Installed as a systemd timer that runs weekly, so neither the
  crawler nor a search request ever has to stop and fetch one.
- **`bin/install.php`** — an idempotent installer that checks requirements,
  writes `.env` using non-echoed/generated credentials, provisions a
  least-privilege database identity, applies schema through a separate admin
  connection, and prints the manual root-only steps for Apache and systemd.
- **`bin/normalize-urls.php`** — re-normalizes stored URLs against the current
  canonicalization rules and merges the rows that turn out to be the same
  resource. Reports and changes nothing without `--apply`.
- **`bin/reextract-text.php`** — re-runs the current text-extraction pipeline
  over every page's stored HTML, so extraction improvements reach the whole
  index immediately instead of waiting a recrawl cycle. Reports and changes
  nothing without `--apply`.

## Tech stack

- **PHP 8.1+** — almost the entire project. A custom autoloader maps flat class
  names to `src/classes/ClassName.php`; no Composer/PSR-4.
- **MariaDB / MySQL** — accessed via `mysqli` with prepared statements
  throughout as the authoritative store. Every hot query is index-driven and
  designed for a multi-million-row catalogue — nothing scans or sorts a whole
  table per request.
- **Manticore Search** — the shared system service holds only DuskRail's
  derived searchable documents, anchor text, and ranking attributes.
- **A real headless Chrome/Chromium instance** — driven directly over its
  DevTools Protocol (a hand-rolled WebSocket client, not a package) for every
  real page/image fetch and for resolving JS bot challenges.
- **ext-gd** for image decoding/thumbnailing, **ext-curl** for the small set of
  fetches that aren't real crawler traffic (robots.txt, sitemaps, the IANA
  TLD list), **ext-mbstring**, **ext-dom** for HTML parsing.
- **Bootstrap** (vendored locally as `bootstrap.min.css` — no CDN at runtime)
  for basic UI styling; vanilla JS for the front end.

## Requirements

- PHP 8.1+ with the `mysqli`, `gd`, `mbstring`, `curl`, and `dom` extensions
  (`ext-posix` is optional — shell fallbacks cover machines without it)
- MariaDB or MySQL
- A system-wide Manticore Search service with a least-privilege application
  user that can read, write, and create only `duskrail_*` tables
- An absolute `THUMBNAIL_DIRECTORY` path. A fresh installation defaults to
  `thumbnails/` in its checkout; point it at mounted bulk storage for a large
  crawl
- A Chrome or Chromium binary on `$PATH` (`chromium-browser`, `google-chrome`,
  `chromium`, or `google-chrome-stable` are auto-detected; set `CHROME_BINARY`
  in `.env` if it's installed under another name). The crawler doesn't
  function without one — `bin/install.php` warns if it can't find one.
- A web server (Apache is what the installer documents) for the search UI
- Optional: `pdftotext` (poppler-utils) — without it, PDFs are skipped
  instead of indexed

## Setup

```sh
git clone https://github.com/FedgeNo/DuskRail.git
cd DuskRail
php bin/install.php
```

The installer checks the PHP environment (including whether a Chrome/Chromium
binary is on `$PATH`), writes `.env` (from `.env.example`'s defaults, or your
answers when run interactively), creates the MariaDB and DuskRail-owned
Manticore schemas, warms the TLD list, and prints the remaining manual steps
that need root — the Apache vhost and systemd units. It does not install,
configure, start, or administer the shared Manticore service.

Run it interactively at least once: it asks for the operator password that the
crawl controls sit behind and stores only its hash. Searching works without it;
until it's set, nobody can sign in to run the crawl — that's deliberate, since
the alternative for an install that skipped it would be letting everybody in.

Configuration lives in `.env` (gitignored); see `.env.example` for the keys —
notably the application-specific `MANTICORE_USERNAME` and
`MANTICORE_PASSWORD`, `WORKER_COUNT`, and `CHROME_BINARY`, which only needs
setting if none of the auto-detected binary names match what's installed. A
`WORKER_COUNT` change takes effect the next time the crawler manager starts.

When upgrading an installation that already has crawled data, run
`php bin/rebuild-search-index.php` once after the installer creates the
Manticore tables. The backfill is resumable and copies only presentable,
searchable items; uncrawled catalogue rows remain solely in MariaDB.

## Usage

Run the crawler supervisor (this is what's meant to run continuously):

```sh
php bin/crawler-manager.php
```

It launches the shared Chrome instance, spawns the worker processes (each
fetching through their own tab in that shared browser), and prints their
per-slot output. Ctrl+C shuts it down gracefully: no new workers get spawned,
in-flight ones are left to finish their current item on their own, and only
then does it shut down the Chrome instance — nothing gets killed mid-crawl.

In normal operation it runs as a systemd service under its own account
instead, with the same graceful shutdown:

```sh
sudo systemctl start duskrail-crawler
sudo systemctl stop duskrail-crawler     # workers finish their current item
sudo journalctl -u duskrail-crawler -f
```

`WORKER_COUNT` (in `.env`) trades off crawl throughput against how many
concurrent tabs — and the memory/CPU each one costs — the machine can
actually sustain.

Then open the site — `https://duskrail.localhost/` by default — to search, or
sign in and open `https://duskrail.localhost/watch.php` to watch the crawl
live, focus it on a topic, and seed new URLs. A name under `.localhost`
resolves to your own machine in every modern browser with no `/etc/hosts`
entry; if you set `SITE_URL` to some other hostname, the installer prints the
hosts line it needs. Run `php bin/test.php` after touching the parsing or
matching logic, and `php bin/backup.php` whenever the index is worth keeping.

## Project layout

```
init.php                bootstrap: constants, autoloader, helpers
src/classes/            all classes (autoloaded by name)
src/functions.php       shared helpers
src/config.php          app config, read from .env via Env
api/                    JSON endpoints: search, item, recent-items,
                        crawler-status, set-topic, add-seed, delete-item
index.php               search UI
login.php / logout.php  operator sign-in
watch.php / watch.js    live crawl feed, topic control, URL seeding
search.js / style.css   front-end behavior and styling
bin/install.php         installer / requirements checker
bin/crawler.php         crawls one item, then exits
bin/crawler-manager.php supervisor: owns the shared Chrome instance, runs
                        WORKER_COUNT concurrent workers
bin/rebuild-search-index.php
                        resumable MariaDB-to-Manticore backfill
bin/sync-search-index.php
                        one-shot durable search-index queue drain
bin/refresh-lists.php   weekly download of the IANA TLD and Public Suffix
                        lists (systemd timer)
bin/normalize-urls.php  re-normalizes and de-duplicates stored URLs
bin/reextract-text.php  re-runs text extraction over stored page HTML
bin/backup.php          timestamped DB dump + thumbnail archive, rotated
manticore-schema.sql    DuskRail-owned tables in the shared search service
bin/test.php            test runner over tests/
tests/                  dependency-free tests for the pure logic
var/                    the running crawler's state files (gitignored)
schema.sql              full-schema snapshot (installer applies deltas)
```

## Status

DuskRail is a from-scratch project and a work in progress. Known limitations
and planned work are tracked in [`TODO.md`](TODO.md).
