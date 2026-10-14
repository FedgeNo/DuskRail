# Interactive-Only Crawling

Set the `Settings` row `interactiveOnly` to `1` to restrict crawling to
active interactive Retrieval API requests (see `retrieval-api.md`). Set it to `0` to resume ordinary
crawling. A missing setting also permits ordinary crawling.

The setting is read at queue selection, before focused crawling or any ordinary
queue is considered. Eligible items have priority 255 and a nonfailed,
unexpired `InteractiveRetrievals` request. Original submissions and selected
evidence pages both qualify. With no eligible requests, workers remain idle.

Sitemap retrieval is disabled in this mode. Robots checks and resources needed
to render the requested document remain enabled. Discovered links can remain
queued, but ordinary pages, recrawls and timed-out interactive requests are
not fetched until ordinary crawling is enabled again. Stored content and
unrelated settings are preserved.

Existing workers may finish their current request when the setting changes.
Each worker reads the setting when it picks an item, so no restart is needed.

From the project root, enable with:

```sh
php -r 'require "init.php"; Setting::store(INTERACTIVE_ONLY_SETTING, "1");'
```

Use `"0"` instead of `"1"` to disable the restriction.

## Crawl windows

`CRAWL_WINDOWED=1` in `.env` applies the same restriction automatically
outside crawl windows: ordinary crawling, recrawls and sitemap ingestion run
only while `bin/crawl-window.php` holds a window open, and interactive
requests are served at all times. The window is renewed in
`CrawlWindow::LEASE_SECONDS` leases, so it closes within a minute if its
holder is killed. Setting `interactiveOnly` to `1` still overrides an open
window.
