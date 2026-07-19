# DuskRail

A search engine — web crawler, index, and search interface — built from the
ground up in PHP. No search framework, no Elasticsearch, no Composer: just a
crawler that fetches and parses the open web, a MariaDB/MySQL FULLTEXT index,
and a small AJAX front end to search it.

## What it does

- **Crawls** pages starting from whatever's in the index, discovering new URLs
  from the links and images on each page and feeding them back into the queue,
  fetching through a real, shared headless Chrome instance so requests are
  genuinely indistinguishable from an ordinary browser's.
- **Indexes** each page's title, description, keywords, and full body text,
  plus a thumbnail for every image it finds.
- **Searches** that index with FULLTEXT ranking, over both HTML pages and
  images, through a web UI with infinite scroll and an image preview panel.

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
  robots.txt fetches and the internal IANA TLD-list refresh use a plain
  (still Chrome-shaped) HTTP client, since neither is traffic against a
  crawled site.
- **JS bot-challenge resolution** — a Cloudflare-style "Just a moment..."
  interstitial is handed to that same shared browser, which runs its
  JavaScript for real (its own verification fetch, redirect, reload) and
  hands back the resulting page; the item is still indexed if no challenge
  or headless browser is available, without ever fetching the challenge a
  second time.
- **Concurrent workers, one shared browser** — a supervisor
  (`bin/crawler-manager.php`) runs several single-item worker processes at
  once, each opening its own isolated tab (separate cookies/storage) in one
  persistent Chrome instance rather than paying its ~1-2 second startup cost
  per item; each item is atomically reserved so no two workers ever crawl the
  same one. The shared browser is health-checked continuously and rotated
  roughly hourly — a rotation drains rather than kills, only tearing down the
  outgoing instance once every worker still using it has finished.
- **Politeness** — per-host request cooldowns (longer after a `429`/`503`), so a
  single host is never hammered.
- **robots.txt** — fetched and cached per host, enforced before every fetch,
  every redirect hop, and every same-host link discovered on a page. Follows
  Google's documented algorithm rather than a plain prefix match: `Allow`/
  `Disallow` patterns (`*` wildcards, trailing `$` end-anchors) scoped to the
  `User-agent: *` group, the longest matching pattern wins, ties go to
  `Allow`.
- **SSRF hardening** — a URL is only crawlable if its host has a real,
  currently-delegated TLD (list fetched from IANA and refreshed weekly). This
  alone rejects IP literals, `localhost`, and internal-only suffixes like
  `.corp`/`.local`.
- **Redirects** — followed with a hop cap and loop protection; a redirect onto
  an already-known URL is de-duplicated rather than stored twice.
- **URL canonicalization** — forces HTTPS, strips tracking parameters
  (`utm_*`, `fbclid`, …) and image cache-busters, sorts query parameters, and
  resolves relative links per RFC 3986.
- **Content extraction** — title/description/keywords from classic meta tags,
  Open Graph, Twitter Cards, and JSON-LD; boilerplate (nav/header/footer/ads)
  stripped before body text is captured; image `alt` text folded in.
- **Image handling** — decodes and thumbnails images, rejecting tracking
  pixels, tiny icons, and decompression bombs.
- **Self-healing queue** — pages that reliably hang or fail are retried a
  bounded number of times, then dropped; nothing that couldn't be turned into
  presentable content is ever left in the index.

### Search

- **FULLTEXT ranking** — `MATCH … AGAINST`, ranked first by how many other
  pages link to a result using matching anchor text (an external relevance
  signal), then by direct content relevance.
- **Pages and images** — toggle between HTML results and an image grid.
- **Infinite scroll** — results paginate in as you scroll; the justified image
  grid lays out each page independently.
- **Image preview** — clicking an image opens a Google-Images-style side panel:
  thumbnail first, then the full-resolution image swapped in once it loads,
  with the full description and a link to the page it was found on.

### Operations

- **`watch.php`** — a live feed of what's being crawled, with a control to focus
  the crawl on a topic (ranking not-yet-crawled URLs by how on-topic the pages
  linking to them are).
- **`bin/install.php`** — an idempotent installer that checks requirements,
  writes `.env`, provisions the database and schema, and prints the manual
  (root-only) steps for the Apache vhost and the crawler systemd service.

## Tech stack

- **PHP 8.1+** — almost the entire project. A custom autoloader maps flat class
  names to `src/classes/ClassName.php`; no Composer/PSR-4.
- **MariaDB / MySQL** — accessed via `mysqli` with prepared statements
  throughout; FULLTEXT indexes for search.
- **A real headless Chrome/Chromium instance** — driven directly over its
  DevTools Protocol (a hand-rolled WebSocket client, not a package) for every
  real page/image fetch and for resolving JS bot challenges.
- **ext-gd** for image decoding/thumbnailing, **ext-curl** for the small set of
  fetches that aren't real crawler traffic (robots.txt, the IANA TLD list),
  **ext-mbstring**, **ext-dom** for HTML parsing.
- **Bootstrap** (CDN) for basic UI styling; vanilla JS for the front end.

## Requirements

- PHP 8.1+ with the `mysqli`, `gd`, `mbstring`, `curl`, and `dom` extensions
- MariaDB or MySQL
- A Chrome or Chromium binary on `$PATH` (`chromium-browser`, `google-chrome`,
  `chromium`, or `google-chrome-stable` are auto-detected; set `CHROME_BINARY`
  in `.env` if it's installed under another name). The crawler doesn't
  function without one — `bin/install.php` warns if it can't find one.
- A web server (Apache is what the installer documents) for the search UI

## Setup

```sh
git clone https://github.com/FedgeNo/DuskRail.git
cd DuskRail
php bin/install.php
```

The installer checks the PHP environment (including whether a Chrome/Chromium
binary is on `$PATH`), writes `.env` (from `.env.example`'s defaults, or your
answers when run interactively), creates the database and schema, warms the
TLD list, and prints the remaining manual steps that need root — the Apache
vhost and the optional `duskrail-crawler` systemd service.

Configuration lives in `.env` (gitignored); see `.env.example` for the keys —
notably `CHROME_BINARY`, which only needs setting if none of the auto-detected
binary names match what's installed.

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
`WORKER_COUNT` (top of the file) trades off crawl throughput against how many
concurrent tabs — and the memory/CPU each one costs — the machine can
actually sustain. On a configured box it can instead run as the
`duskrail-crawler` systemd service.

Then open the site (the Apache vhost the installer sets up, e.g.
`http://duskrail.local/`) to search, or `http://duskrail.local/watch.php` to
watch the crawl live and set a focus topic.

## Project layout

```
init.php                bootstrap: constants, autoloader, helpers
src/classes/            all classes (autoloaded by name)
src/functions.php       shared helpers
src/config.php          app config, read from .env via Env
api/                    JSON endpoints: search, item, recent-items, …
index.php               search UI
watch.php / watch.js    live crawl feed + topic control
search.js / style.css   front-end behavior and styling
bin/install.php         installer / requirements checker
bin/crawler.php         crawls one item, then exits
bin/crawler-manager.php supervisor: owns the shared Chrome instance, runs
                        WORKER_COUNT concurrent workers
schema.sql              full-schema snapshot (installer applies deltas)
```

## Status

DuskRail is a from-scratch project and a work in progress. Known limitations
and planned work are tracked in [`TODO.md`](TODO.md).
