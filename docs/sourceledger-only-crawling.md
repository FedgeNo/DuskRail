# SourceLedger-Only Crawling

Set the `Settings` row `sourceLedgerOnly` to `1` to restrict crawling to
active SourceLedger interactive requests. Set it to `0` to resume ordinary
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
Restart `duskrail-crawler.service` to drain the old workers and start fresh ones.

From the project root, enable with:

```sh
php -r 'require "init.php"; Setting::store(SOURCELEDGER_ONLY_SETTING, "1");'
```

Use `"0"` instead of `"1"` to disable the restriction.
