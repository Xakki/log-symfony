---
name: log-pipeline
description: Architecture of xakki/log-symfony's PSR-3 log-enrichment pipeline — EnrichingLogger (PSR-3-only decorator, no Monolog required) vs ContextEnrichProcessor (monolog.processor, needs Monolog 3) integration paths, ContextEnricher context-field type rules (suffix/reserved/exact/word/prefix), LoggerConfig, Redactor credential redaction, FileTrace call-site/stack-trace resolution, RequestId correlation, and the request_id/remote_ip/const.php Graylog-OpenSearch field-schema contract shared with xakki/phperrorcatcher
---

# log-symfony pipeline architecture

`xakki/log-symfony` is a PSR-3 log-enrichment library (no framework in `require`) that enriches,
type-corrects and redacts log context before it reaches a handler. It is the Symfony-side sibling
of `xakki/laralog` (read-only reference checkout `/home/xakki/log-laravel`).

**Core is Monolog-free.** `ContextEnricher` and `Logger\EnrichingLogger` (Path B) depend on
`psr/log` only — `Psr\Log\LogLevel::*` strings, not `Monolog\Level`. Monolog 3 is needed only
for `Processor\ContextEnrichProcessor`/`ExtraProcessor`/`RemoteIpProcessor` and `Formatters\*`
(Path A), which is why `monolog/monolog` lives in `require-dev` + `suggest`, not `require` — see
§0 below. Owner decision 2026-09-07 (the concrete driver: `xakki/convertor` logs through
`xakki/fluent-log`, a plain PSR-3 logger with no Monolog installed at all).

**Self-currency:** every fact below cites the source file/symbol it comes from. Before relying
on a fact here, verify it against that source — code wins on drift. If you find drift while
working, fix it in this skill in the same change and report it to the team-lead.

## 0. Requires

* `php: ^8.4`, `psr/log: ^3.0`, `ext-mbstring` — the whole hard `require` (`composer.json`).
* `monolog/monolog: ^3.0` — `require-dev` (this repo's own test suite exercises both paths) +
  `suggest`, **not** `require`. Needed only if the host app uses Path A.
* Proven, not just declared: an executable probe (composer `path` repository, `--no-dev`
  install, asserts `vendor/monolog` absent) wires `EnrichingLogger` over a hand-rolled PSR-3
  logger and confirms enrichment still works with zero Monolog present — see
  `tests/Unit/EnrichingLoggerPsr3OnlyTest.php` for the in-suite equivalent (a
  `Psr\Log\AbstractLogger` test double, never `Monolog\Logger`/`TestHandler`).

## 1. The pipeline end to end

All enrichment logic lives in one place, `ContextEnricher::enrich()` (`src/ContextEnricher.php`),
called by whichever of the two integration paths is wired. Inside `enrich()`, in order:

1. The `LOGGER_SKIP_TRACE` context key (`src/const.php`) is read and stripped — opts out of
   both call-site and exception trace resolution for this record.
2. An `exception` context value (a `\Throwable`) is unwrapped and removed from `$context`.
3. `log_type` is resolved (`LogType::current()`, `src/LogType.php`) — an explicit
   `context['log_type']` wins; otherwise an exception present alongside the default `logger`
   origin is promoted to `exception`.
4. `contextTypeCorrector()` runs over `$context` **as it stands at this point** — credential
   redaction by key (`Redactor::shouldRedactKey()`) then type coercion per the rule table (§3
   below). This happens *before* the exception/file/trace/memory/request_id fields are added,
   so those library-written fields are set with already-correct native types and never pass
   back through the corrector.
5. Exception fields are written directly (`exception`, `exception_code`, `file`, `trace` unless
   skipped, and the `exception_prev*` chain) via `FileTrace` (`src/FileTrace.php`).
6. `message_len` is computed.
7. If `file` is still empty (no exception on this record), the call site is resolved from
   `debug_backtrace()` via `FileTrace::getFileLine()`, and a trace is attached
   (`FileTrace::traceToString()`) when the level is `Warning+` and depth-scaled by
   `LoggerConfig::$traceDepth`.
8. `memory_usage`/`memory_peak` are attached when `LoggerConfig::$allowMemory` is true.
9. `request_id` is attached via `RequestId::get()` (`src/RequestId.php`).
10. The message is truncated to `LoggerConfig::$messageLimit` and returned.

### Path A — `Processor\ContextEnrichProcessor` (the primary integration point)

Implements Monolog's `ProcessorInterface`, tagged `monolog.processor` (see `Readme.md`
"Wiring"). It runs inside `Monolog\Logger::addRecord()` — several frames deep in **Monolog's
own call stack** — before any handler sees the record (`LogRecord::$context`/`$message` are
readonly, so it enriches a copy and returns a new record via `LogRecord::with()`). Because it
sits deep in Monolog's internals, the top frames of a raw `debug_backtrace()` at this point
belong to Monolog and to this package's own processor class, not the application — that's what
`FileTrace`'s frame exclusion (§4) exists to walk past. Covers every record on every
logger/channel it's tagged for, with no call-site changes needed — the default choice.

### Path B — `Logger\EnrichingLogger` (optional PSR-3 decorator)

Implements `Psr\Log\LoggerInterface` (`src/Logger/EnrichingLogger.php`), wraps an inner
`LoggerInterface` + `ContextEnricher`, and calls `enrich()` directly at the application's call
site — one frame away, before the message ever reaches Monolog's handler chain at all. No
Monolog-internal frames ever need filtering on this path. Requires nothing beyond `psr/log`:
the inner logger can be Monolog, `xakki/fluent-log`, or any other `LoggerInterface`
implementation — `EnrichingLogger::log()` normalizes `$level` to a validated PSR-3 string
itself (`Psr\Log\LogLevel::*`) and hands that straight to `ContextEnricher::enrich()`, which
never touches `Monolog\Level`. Pick it when call-site `file`/`trace` precision matters (e.g.
decorating Symfony's default `logger` service), when the host has no Monolog at all, and blanket
processor-level coverage isn't the goal; per `Readme.md`, "drop this block if
`ContextEnrichProcessor` alone is enough for your app."

Downstream of either path: `Processor\ExtraProcessor` and `Processor\RemoteIpProcessor` (also
tagged `monolog.processor`) add their own fields, then the handler formats the record —
`Formatters\ConsoleFormatter` (colorized, human-readable, for local/CLI dev) or
`Formatters\CustomFormatter` (compact single-line JSON, adaptive size trimming to a `logSize`
budget) — before it's written to the sink.

## 2. Class map (`src/`)

| File | Responsibility | Collaborators |
|---|---|---|
| `src/const.php` | Global constants (`LOGGER_VER`, `LOGGER_MCTIME`, `LOGGER_MEMORY`, `LOGGER_SKIP_TRACE`, …); autoloaded via `composer.json`'s `autoload.files` | Cross-repo contract — see §5 |
| `src/ContextEnricher.php` | Core enrichment: `log_type`, exception unwrap, context type coercion + redaction dispatch, file/trace fields, `request_id`, message truncation. `enrich()` takes a PSR-3 level STRING (`Psr\Log\LogLevel::*`), not `Monolog\Level` — a private `LEVEL_RANK` table (values match `Monolog\Level::value` 1:1) restores the severity ordering PSR-3 doesn't define, for the `traceDepth` warning/error/critical buckets | `LoggerConfig`, `Redactor`, `FileTrace`, `RequestId`, `LogType`; consumed by both integration paths; zero Monolog dependency itself |
| `src/FileTrace.php` | Call-site `file:line` resolution + stack-trace rendering; frame exclusion (`traceExcludedPartials` + own `__DIR__`) | `LoggerConfig` (excluded partials, arg limit), `Redactor` (positional trace-arg redaction) |
| `src/LoggerConfig.php` | Typed immutable config value object + `fromArray()` factory; never reads `env()`/`config()` itself | Injected into `ContextEnricher`, `FileTrace`, `Processor\ExtraProcessor` |
| `src/LogType.php` | Process-scoped log-origin classifier (`logger`/`trigger`/`exception`/`fatal`); `installHandlers()` chains error/exception/shutdown handlers when opted in | Read by `ContextEnricher::enrich()`; gated by `LoggerConfig::$captureHandlers` |
| `src/Redactor.php` | Credential redaction: `shouldRedactKey()` (by field name) and `redactValue()` (by value pattern — Bearer/JWT/URL-creds/`key=value`) | Used by `ContextEnricher::contextTypeCorrector()` and `FileTrace` |
| `src/RequestId.php` | Per-instance request-correlation id: inbound header or generated UUIDv4; `reset()` for reuse across units of work | Read by `ContextEnricher::enrich()` |
| `src/Formatters/ConsoleFormatter.php` | `LineFormatter` subclass — colorized human-readable output for local/CLI dev | Extends `Monolog\Formatter\LineFormatter` |
| `src/Formatters/CustomFormatter.php` | `NormalizerFormatter` subclass — compact JSON with adaptive size trimming | Extends `Monolog\Formatter\NormalizerFormatter` |
| `src/Logger/EnrichingLogger.php` | PSR-3 decorator — integration Path B (§1) | `ContextEnricher`, an inner `Psr\Log\LoggerInterface` |
| `src/Processor/ContextEnrichProcessor.php` | Monolog processor — integration Path A (§1). Converts `$record->level` (`Monolog\Level`) to its PSR-3 name (`->toPsrLogLevel()`) before calling `ContextEnricher::enrich()` | `ContextEnricher` |
| `src/Processor/ExtraProcessor.php` | Copies `LoggerConfig::$extra` (process-stable fields) + `console_argv` onto `LogRecord::$extra`, computed once at construction | `LoggerConfig` |
| `src/Processor/RemoteIpProcessor.php` | Adds `context.remote_ip` from `$_SERVER` (`REMOTE_ADDR`, optionally trusted `X-Forwarded-For`) | None — reads `$_SERVER` directly |

## 3. Context-field type rules (`ContextEnricher::$contextTypeRules`)

**Why it matters:** OpenSearch has no `ignore_malformed`; a field's type is fixed by the first
document that carries it. A later record with the same field name but a different type is
**silently dropped whole** — no error, no trace. The rule table exists to make the field *name*
predict its type consistently across every log site (and across `xakki/phperrorcatcher`, which
writes into the same index — see §5). Full dictionary, worked examples and pitfalls:
`docs/ContextFieldNaming.md` (English, canonical) / `docs/ContextFieldNaming.ru.md` — don't
duplicate those tables here.

Five rule kinds, resolved by `ContextEnricher::contextResolveType()`, **first match wins**:

1. **`suffix`** — escape hatch. Last segment is literally `int`/`float`/`bool` → that type,
   always. Highest priority, beats every other bucket including `reserved`.
2. **`reserved`** — whole key (checked raw, then lowercased); fields this library writes itself
   (`log_type`, `file`, `trace`, `exception*`, `request_id`, …), pinned so a project rule can't
   regress them.
3. **`exact`** — whole key, lowercased; the project/host override level
   (`LoggerConfig::$contextTypeRules`).
4. **`word`** — the last `_`-delimited segment (`user_id` → `id` → int).
5. **`prefix`** — the first segment, only for 2+-segment keys, only for boolean prefixes
   (`is_admin` → bool).
6. No rule matched → the default, a string.

Project rules merge onto the shared base fresh on every call (`ContextEnricher::contextTypeRules()`)
and never write back into it — see that method's docblock for why (a prior caching bug let one
instance's project rules leak into every other instance).

## 4. Stack-depth constraint

`LoggerConfig::$traceExcludedPartials` defaults to `['Monolog/', 'xakki/log-symfony/', 'vendor/']`
— **not** the same list LaraLog uses, because Path A runs inside `Monolog\Logger::addRecord()`,
so the frames needing exclusion are Monolog's own source files, not `Illuminate/Log/` (Symfony
ships no bundled log-manager layer to exclude in the first place — see `Readme.md`'s
`traceExcludedPartials` row and `FileTrace`'s class docblock). Separately,
`FileTrace::checkExcludePart()` *always* excludes frames under its own `__DIR__` regardless of
config — that's what makes Path B (`EnrichingLogger`, called from this package's own
`Logger/EnrichingLogger.php`, not from Monolog) resolve correctly even though neither
`'Monolog/'` nor `'vendor/'` would match its frames on a dev checkout.

`tests/Unit/StackDepthTest.php` is the executable proof: it logs through a **real**
`Monolog\Logger` (not a stub) over both integration paths and asserts the resolved `file`
context field lands on the test's own file, never under `src/` or inside Monolog. Anyone
touching frame-walking code — `FileTrace`, `ContextEnricher`'s `debug_backtrace()` call, or the
`traceExcludedPartials` default — must re-run this test; a passing suite that doesn't exercise
this assertion proves nothing about frame resolution.

## 5. Cross-repo contracts — read before touching any of these

- **`src/const.php` constant *string values*** are shared byte-for-byte with
  `xakki/phperrorcatcher` and a Python port — they are the literal field names landing in
  OpenSearch. Never rename one or change a value "to clean it up". `LOGGER_VER` marks the
  **schema** version, not this package's release version.
- **`docs/ContextFieldNaming.md` / `.ru.md`** rule tables are shared word-for-word with
  `PhpErrorCatcher::$contextTypeRules` / `contextTypeRulesExtra` — see that doc's §7
  ("Where this lives in code"). Editing the dictionary means editing both tables *and* both
  test suites in the same change.
- **`request_id` inbound precedence** (`RequestId::resolveInbound()`, `src/RequestId.php`):
  `Request-Id` wins over `X-Request-Id` when both arrive with different values — deliberately
  mirroring `xakki/laralog`'s order so the two packages correlate the same request to the same
  id (owner decision 2026-09-06). Pinned by
  `tests/Unit/RequestIdTest.php::testInboundRequestIdWinsOverXRequestId`.
- **`remote_ip` lives in `context`, not `extra`** (`Processor\RemoteIpProcessor`) — it has
  request lifetime, not process lifetime, so pinning it in `extra` would go stale after the
  first request handled by a long-lived worker. See `docs/LoggingRules.md` §4.2.

## 6. Porting further from log-laravel

Reference checkout: `/home/xakki/log-laravel` — read it, never modify it (see this project's
`CLAUDE.md`, "Porting from log-laravel"). Laravel extension points with **no** Symfony
equivalent, needing a redesign rather than a straight port:

- `config/logging.php`'s `'tap' => [...]` array — a Laravel-framework post-build hook over the
  compiled Monolog `Logger`; `symfony/monolog-bundle` has no equivalent concept.
- The `'driver' => 'custom', 'via' => Class::class` custom-handler-factory config contract —
  `monolog-bundle` configures handlers declaratively (`type: service`) instead.
- `DB::listen()` / `Illuminate\Database\Events\QueryExecuted` — Doctrine DBAL offers a
  `Middleware`/`Driver`/`Connection`/`Statement` decorator chain instead.
- `Queue::popUsing()` and the Illuminate queue events — Symfony Messenger's worker events
  (`WorkerStartedEvent`, `WorkerRunningEvent`, `WorkerMessageReceivedEvent`,
  `WorkerStoppedEvent`) via an `EventSubscriberInterface` instead.

Open follow-ups for these (including a real `request_id`-leak-across-messages risk in
long-lived workers) are tracked as kanban cards in `.claude/kanban/todo/`.
