# DuskRail

A search engine — web crawler, index, and search interface — built from the
ground up in PHP. No search framework, no Elasticsearch, no Composer: just a
crawler that fetches and parses the open web, a MariaDB/MySQL FULLTEXT index,
and a small AJAX front end to search it.

## What it does

- **Crawls** pages starting from whatever's in the index, discovering new URLs
  from the links and images on each page and feeding them back into the queue.
- **Indexes** each page's title, description, keywords, and full body text,
  plus a thumbnail for every image it finds.
- **Searches** that index with FULLTEXT ranking, over both HTML pages and
  images, through a web UI with infinite scroll and an image preview panel.

## Features

### Crawler

- **Concurrent workers** — a supervisor (`bin/crawler-manager.php`) runs several
  single-item worker processes at once; each item is atomically reserved so no
  two workers ever crawl the same one.
- **Politeness** — per-host request cooldowns (longer after a `429`/`503`), so a
  single host is never hammered.
- **robots.txt** — fetched and cached per host, enforced before every fetch,
  every redirect hop, and every same-host link discovered on a page.
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
- **ext-gd** for image decoding/thumbnailing, **ext-curl** for fetching,
  **ext-mbstring**, **ext-dom** for HTML parsing.
- **Bootstrap** (CDN) for basic UI styling; vanilla JS for the front end.

## Requirements

- PHP 8.1+ with the `mysqli`, `gd`, `mbstring`, `curl`, and `dom` extensions
- MariaDB or MySQL
- A web server (Apache is what the installer documents) for the search UI

## Setup

```sh
git clone https://github.com/FedgeNo/DuskRail.git
cd DuskRail
php bin/install.php
```

The installer checks the PHP environment, writes `.env` (from `.env.example`'s
defaults, or your answers when run interactively), creates the database and
schema, warms the TLD list, and prints the remaining manual steps that need
root — the Apache vhost and the optional `duskrail-crawler` systemd service.

Configuration lives in `.env` (gitignored); see `.env.example` for the keys.

## Usage

Run the crawler supervisor (this is what's meant to run continuously):

```sh
php bin/crawler-manager.php
```

It spawns the worker processes, prints their per-slot output, and shuts down
gracefully on Ctrl+C — letting in-flight workers finish their current item
rather than killing mid-crawl. On a configured box it can instead run as the
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
bin/crawler-manager.php supervisor running many workers concurrently
schema.sql              full-schema snapshot (installer applies deltas)
```

## Status

DuskRail is a from-scratch project and a work in progress. Known limitations
and planned work — notably a headless-browser step for JavaScript-challenge
pages and a fuller robots.txt parser — are tracked in
[`TODO.md`](TODO.md).
