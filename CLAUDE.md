# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`xakki/log-symfony` — a **PSR-3 log-enrichment library** (not a Symfony bundle) that enriches,
type-corrects and redacts log context before it reaches a handler. Its core (`ContextEnricher`,
`Logger\EnrichingLogger`) is Monolog-free; Monolog 3 is needed only for the processor/formatter
path (`Processor\ContextEnrichProcessor` and friends, `Formatters\*`) — see skill
`.claude/skills/log-pipeline/`, §0. It is the Symfony-side sibling of `xakki/laralog`
(checkout: `/home/xakki/log-laravel`), which targets Laravel; both feed the same
Graylog/OpenSearch field schema.

Architecture (the log pipeline, class map, the shared field-name contract) — skill
`.claude/skills/log-pipeline/`. The language-agnostic logging spec that both packages
implement — `docs/LoggingRules.md`.

## Commands

Everything runs in Docker via `make` — **never** a host `php`/`composer` binary.
Targets are documented in the `Makefile` itself (`make help`; its output is injected
into every session). Gates before finishing: `make test` (cs-check + phpstan + phpunit).

- One test: `make phpunit-filter name=<TestMethodOrClass>`.
- A container run that stamped files as root → `make fix-perms`.

## Hard constraints

- **No framework in `require`.** `composer.json` requires only php + psr/log + ext-mbstring.
  `monolog/monolog` lives in `require-dev` + `suggest` (needed only by the
  `Processor\*`/`Formatters\*` classes, not by `ContextEnricher`/`Logger\EnrichingLogger`) —
  Symfony, Doctrine and Messenger belong in `suggest` / `require-dev` too. Every class must be
  constructible with plain `new`. No `config()`, `env()`, `getenv()` or container lookups
  anywhere in `src/` — the host app builds a `LoggerConfig` once.
- **`src/const.php` is a cross-repo contract.** The constant *string values* are shared
  byte-for-byte with `xakki/phperrorcatcher` and a Python port, and are the field names
  landing in OpenSearch. Never rename one or change a value to "clean it up";
  `LOGGER_VER` marks the *schema* version, not this package's version.
  Same for the rule tables in `docs/ContextFieldNaming*.md` — shared word-for-word.
- **phpstan level 8, no baseline, no ignores.** Fix the code. Growing a baseline needs an
  explicit approval from the owner.
- **Symfony version support is a documented claim, not a constraint** — CI runs PHP 8.4/8.5 (+ an 8.6 canary)
  against Monolog only, never against Symfony; nothing in `src/` touches a Symfony API. `Readme.md`
  says SF 6.4 LTS / 7.x "supported" (a claim about the wiring examples, not linked code) and 8.0
  "expected to work unchanged" until GA. Neither is verified by actually booting a Symfony app —
  that is card `LSYM-009` (Readme wiring snippets) / `LSYM-006` (SF 8.0 GA readiness).

## Porting from log-laravel

When porting anything further from `/home/xakki/log-laravel`, read it but never modify it.
The Laravel-only extension points that have **no** Symfony equivalent and need a redesign
rather than a copy: the `logging.php` `tap` array, the `'driver' => 'custom', 'via' =>`
handler factory contract, `DB::listen()`, and `Queue::popUsing()`. Open follow-ups —
`.claude/kanban/`.
