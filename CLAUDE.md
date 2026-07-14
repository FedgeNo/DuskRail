# DuskRail

A search engine (crawler, index, and search interface) built from the ground up.

## Working rules

- Do not use the advisor tool ("consult") on this project.
- Do not use the Workflow tool (multi-agent orchestration) on this project.
- Do not spawn multiple subagents/agents in parallel unless the user explicitly asks for it. Work directly, one model/session at a time.
- Never use the AskUserQuestion pop-up tool. Ask clarifying questions as plain text in the conversation instead.
- Never use the persistent memory system (no memory files, no MEMORY.md entries). This CLAUDE.md is the only durable record of working rules and project context — keep it up to date instead.

## Tech stack

- Language: PHP (almost the entire project). No Composer/PSR-4 — a custom autoloader instead.
- `init.php` is the central bootstrap file: defines base constants, registers the autoloader, loads `src/functions.php`, and loads `config/config.php` if present.
- `src/classes/` holds all classes, one per file, autoloaded directly by class name (no namespaces — flat class names map straight to `src/classes/ClassName.php`).
- `src/functions.php` holds shared helper functions.
- `config/config.php` holds local/production secrets (DB credentials, etc.) and is gitignored; `config/config.example.php` is the committed template.

## Project structure

- `init.php` — bootstrap, require this first in any entry point.
- `src/classes/` — all classes (autoloaded).
- `src/functions.php` — shared helper functions.
- `config/` — config.php (gitignored) + config.example.php (template).
