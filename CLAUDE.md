# DuskRail

A search engine (crawler, index, and search interface) built from the ground up.

## Working rules

- Never delegate work on this project: no advisor tool ("consult"), no Workflow tool (multi-agent orchestration), no Agent tool/subagents of any kind (not even a single one, not even sequentially) — regardless of how the user phrases the request. Do the work directly yourself, one model/session at a time.
- Never use the AskUserQuestion pop-up tool, for any reason, including to offer multiple-choice options or to confirm an approach before implementing it. This has been violated before. Ask clarifying questions as plain text in the conversation instead, always.
- Never use the persistent memory system (no memory files, no MEMORY.md entries). This CLAUDE.md is the only durable record of working rules and project context — keep it up to date instead.
- `bin/install.php` must mirror every environment-changing setup step taken on the dev machine (directories, .env/config, database creation/users, schema, Apache/vhost config, package installs, etc.) so the project can be stood up from scratch on a fresh box. Update it in the same step as making the change, not after the fact.
- Schema changes go into `bin/install.php`'s `schema_deltas()` list as a new entry appended in the order the change was actually made (oldest first) — never edit an old delta's `apply` in place once it's shipped. Each delta needs a `check` (detects the live DB already reflects it) so re-running stays idempotent/self-healing. Update `schema.sql` alongside as the current full-schema snapshot for manual bootstrapping.
- When stopping `bin/crawler-manager.php` (or its worker processes), give in-flight workers time to finish their current item rather than force-killing everything immediately — a mid-crawl kill wastes the work in progress. Signal the manager and wait/poll for it and its children to exit on their own before considering it stopped.

## Tech stack

- Language: PHP (almost the entire project). No Composer/PSR-4 — a custom autoloader instead.
- `init.php` is the central bootstrap file: defines base constants, registers the autoloader, and loads `src/functions.php`.
- `src/classes/` holds all classes, one per file, autoloaded directly by class name (no namespaces — flat class names map straight to `src/classes/ClassName.php`).
- `src/functions.php` holds shared helper functions.
- `src/config.php` returns the app config array, built from `Env::get(...)` calls (`Env` reads `.env`).
- `.env` holds local/production secrets (DB credentials, etc.) and is gitignored; `.env.example` is the committed template.
- DB access via mysqli (`Database::connection()`), not PDO.
- Every PHP file starts with `declare(strict_types=1);`.
- Comments explain *why*, not what — used for non-obvious constraints/decisions, not restating the code.
- Spaces around `->` and `?->`: `$this -> property`, `$foo -> bar()`, `$foo ?-> bar`. Applies everywhere, no exceptions for short chains.

## Crawler conventions

- `crawledTime` being non-`NULL` means "this item is real, presentable content" - never stamp it on an item the crawler couldn't actually turn into something worth showing a search result for. If crawling something fails in a way that leaves nothing usable (an undecodable image, a redirect that couldn't be resolved to anything, a broken/looping redirect, a non-2xx status code), delete the item instead of marking it crawled with empty/null/error-page fields. Exception: 429/503 means "slow down", not "this doesn't exist" - leave the item alone (`crawledTime` still `NULL`) for a later retry instead of deleting it.
- Politeness: every `Items` row has a `hostId` FK to `Hosts`, which tracks `nextCrawlTime` (default +60s after a request, +300s after a 429/503) - `Item::nextToCrawl()` only ever considers items whose host is actually due. Every real HTTP request (each redirect hop included, not just the final one) calls `Host::recordCrawl()`. `Hosts.robotsTxt` is fetched and cached once per host (`Host::fetchRobotsTxtIfMissing()`) and enforced via `Host::isDisallowed()` - deliberately a plain `Disallow:` prefix match, no User-agent groups/wildcards/Allow support (see `TODO.md`). Checked before fetching the item itself, before following each redirect hop, and before inserting a same-host image/link discovered on the page (never checked for other hosts a page merely links to).
- Concurrency: `bin/crawler-manager.php` runs several `bin/crawler.php` processes at once (`WORKER_COUNT`), so `Item::nextToCrawl()` atomically reserves the row it returns via `Items.claimedUntil` (a short-lived reservation, not a real crawl outcome) - `crawledTime` itself is untouched by claiming and still only ever set by `markCrawled()` on success. Ordering always prefers a never-attempted item over one whose claim expired without completing (a crashed/hung worker) - a stalled-out item is retried only once every fresh and normal-recrawl candidate is exhausted, not immediately requeued ahead of everything else.
- Images below a reasonable minimum dimension (`ImageLoader::MIN_DIMENSION`) are rejected the same way an undecodable image is - a tracking pixel, spacer gif, or decorative icon/arrow/bullet isn't presentable search content.

## SQL conventions

- Use prepared statements (`mysqli_prepare` + `mysqli_stmt_bind_param`) for every query with a variable value, no exceptions — even hardcoded literals you wrote yourself. The one real exception: `SHOW ... LIKE ?` refuses to prepare at all on MariaDB/MySQL (confirmed directly — `mysqli_prepare()` returns `false`), so those specific statements fall back to `mysqli_real_escape_string()`.
- Backtick-quote every individual identifier (table, column, alias) — never wrap the whole query in backticks, only the identifiers themselves.
- An identifier that has to be interpolated raw (a database/table/username in DDL/DCL, where MySQL never accepts a placeholder) must be validated first (`validate_identifier()` in `bin/install.php`: letters/digits/underscore only), not just escaped.
- Never pass the DB connection around as a method/function parameter — call `Database::connection()` directly wherever it's needed. Assign it to a local variable only within a single method/script that reuses it more than once.
- Every id column is `int(10) unsigned` (never signed) — matches existing `itemId`/`parentId`/`childId`.
- Multi-line SQL string formatting: opening quote ends its line with nothing after it; the first keyword (`SELECT`/`INSERT`/`UPDATE`/`DELETE`/`ALTER`) starts flush at column 0 on the next line; every subsequent clause keyword (`FROM`, `WHERE`, `SET`, `VALUES`, `ORDER BY`, ...) is indented 4 spaces, flush with each other; the closing `');` is flush at column 0 on its own line. Applies even to short/one-line-looking queries.

## Project structure

- `init.php` — bootstrap, require this first in any entry point.
- `src/classes/` — all classes (autoloaded).
- `src/functions.php` — shared helper functions.
- `src/config.php` — app config array (reads from `.env` via `Env`).
- `.env` / `.env.example` — secrets (gitignored) / committed template.
- `bin/install.php` — CLI installer: checks requirements, writes `.env`, provisions the database/user, applies schema. Run via `php bin/install.php`.
- `bin/crawler.php` — crawls a single item (`Item::nextToCrawl()`), then exits. Not meant to be run directly in normal operation — see `bin/crawler-manager.php`.
- `bin/crawler-manager.php` — supervisor loop: runs `WORKER_COUNT` (currently 5) concurrent `bin/crawler.php` processes, one item per worker slot at a time, killing (SIGKILL) any run that hangs past 30 seconds and deleting an item that hangs 3 times in a row. Each worker's output is line-buffered and prefixed (`[0]`, `[1]`, ...) so concurrent output stays readable. Run via `php bin/crawler-manager.php`; this is what's actually meant to run continuously.
- `crawler-current-item-N` — one per worker slot (gitignored), written by `bin/crawler.php` right after picking its item, read by `bin/crawler-manager.php` to identify which slot's process hung after killing it.
