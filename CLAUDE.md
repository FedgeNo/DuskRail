# DuskRail

A search engine (crawler, index, and search interface) built from the ground up.

## Working rules

- Do not use the advisor tool ("consult") on this project.
- Do not use the Workflow tool (multi-agent orchestration) on this project.
- Do not spawn multiple subagents/agents in parallel unless the user explicitly asks for it. Work directly, one model/session at a time.
- Never use the AskUserQuestion pop-up tool. Ask clarifying questions as plain text in the conversation instead.
- Never use the persistent memory system (no memory files, no MEMORY.md entries). This CLAUDE.md is the only durable record of working rules and project context — keep it up to date instead.
- `bin/install.php` must mirror every environment-changing setup step taken on the dev machine (directories, .env/config, database creation/users, schema, Apache/vhost config, package installs, etc.) so the project can be stood up from scratch on a fresh box. Update it in the same step as making the change, not after the fact.
- Schema changes go into `bin/install.php`'s `schema_deltas()` list as a new entry appended in the order the change was actually made (oldest first) — never edit an old delta's `apply` in place once it's shipped. Each delta needs a `check` (detects the live DB already reflects it) so re-running stays idempotent/self-healing. Update `schema.sql` alongside as the current full-schema snapshot for manual bootstrapping.

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
- `bin/crawler-manager.php` — supervisor loop: runs `bin/crawler.php` repeatedly, one process per item, killing (SIGKILL) any run that hangs past 30 seconds and deleting an item that hangs 3 times in a row. Run via `php bin/crawler-manager.php`; this is what's actually meant to run continuously.
- `crawler-current-item` — runtime state file (gitignored), written by `bin/crawler.php` right after picking its item, read by `bin/crawler-manager.php` to identify a hung process after killing it.
