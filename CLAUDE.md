# DuskRail

A search engine (crawler, index, and search interface) built from the ground up.

## Working rules

- Do not use the advisor tool ("consult") on this project.
- Do not use the Workflow tool (multi-agent orchestration) on this project.
- Do not spawn multiple subagents/agents in parallel unless the user explicitly asks for it. Work directly, one model/session at a time.
- Never use the AskUserQuestion pop-up tool. Ask clarifying questions as plain text in the conversation instead.
- Never use the persistent memory system (no memory files, no MEMORY.md entries). This CLAUDE.md is the only durable record of working rules and project context — keep it up to date instead.
- `bin/install.php` must mirror every environment-changing setup step taken on the dev machine (directories, .env/config, database creation/users, schema, Apache/vhost config, package installs, etc.) so the project can be stood up from scratch on a fresh box. Update it in the same step as making the change, not after the fact.

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

## Project structure

- `init.php` — bootstrap, require this first in any entry point.
- `src/classes/` — all classes (autoloaded).
- `src/functions.php` — shared helper functions.
- `src/config.php` — app config array (reads from `.env` via `Env`).
- `.env` / `.env.example` — secrets (gitignored) / committed template.
- `bin/install.php` — CLI installer: checks requirements, writes `.env`, provisions the database/user, applies schema. Run via `php bin/install.php`.
