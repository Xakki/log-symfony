
# LogSymfony

Structured production logging — a redactor and context-enrichment core (`ContextEnricher`) with
two ways to wire it in, plus Monolog formatters. **Not** a Symfony bundle: wire it yourself (see
[Wiring](#wiring) below).

# Two integration paths

| Path | Class | Needs |
|---|---|---|
| **A — Monolog processor** (the primary integration point) | `Processor\ContextEnrichProcessor` (+ `ExtraProcessor`, `RemoteIpProcessor`, `Formatters\*`) | `monolog/monolog: ^3.0`. Tag it `monolog.processor` via `config/packages/monolog.yaml` / `symfony/monolog-bundle`. |
| **B — PSR-3 decorator** (optional) | `Logger\EnrichingLogger` | `psr/log` only. Wraps ANY `Psr\Log\LoggerInterface` — works with `xakki/fluent-log`, a bare `Psr\Log\NullLogger`, or Monolog; no Monolog install required. |

Both paths share the same enrichment logic (`ContextEnricher`), which is itself PSR-3-only and
carries no Monolog dependency — see [Requires](#requires). Pick A for blanket, config-driven
coverage of every Monolog handler/channel; pick B for a Monolog-free app (e.g. one logging
through `xakki/fluent-log`) or when one call site needs precise `file`/`trace`.

# Documentation

* [Logging Rules](./docs/LoggingRules.md) ([RU](./docs/LoggingRules.ru.md)) — language-agnostic spec for production logging; LogSymfony as the reference PHP/Symfony implementation
* [Context field naming](./docs/ContextFieldNaming.md) ([RU](./docs/ContextFieldNaming.ru.md)) — how to name `context` fields so their type stays stable across services
* [Graylog integration](./docs/Graylog.md)
* [Python logging via fluent-bit](./docs/PhytonLog.md) — shipping Python stdlib `logging` into the same Graylog GELF pipeline (direct GELF + fluent-bit text-parse paths)

# What you get

* `log_type` — who emitted it: `logger` (code) / `trigger` (PHP error) / `exception` / `fatal` (spec §4.3.1)
* `request_id` — per-request correlation id (inbound `Request-Id` header, else `X-Request-Id`, else generated)
* `file` + `trace` — call site and a stack trace whose depth scales with level (Monolog/vendor
  frames stripped, so it resolves to real application code even from inside a Monolog processor
  deep in the handler chain — see `traceExcludedPartials` below)
* `message_len`, and `memory_usage`/`memory_peak` when `allowMemory` is on
* type coercion of context values (`*_id`→int, `is_*`→bool, …) and optional `snakeCase` of keys
* **credential redaction** — `password`/`token`/`api_key`/… masked to `***` by field name, on by default

Plus an extra-fields processor (`Processor\ExtraProcessor`) adds stable per-process fields
(`app_name`, `app_env`, `app_ver`, `log_ver`, `tier`, `release_*`, …) from `LoggerConfig::$extra`,
and a `Processor\RemoteIpProcessor` adds `context.remote_ip` from `$_SERVER` (request-lifetime
data, so `context`, not `extra` — see `LoggingRules.md` §4.2/§4.3).

# Requires

* php: ^8.4 (Composer's caret on PHP means `>=8.4 <9.0` — 8.5 and later minors are covered)
* psr/log: ^3.0
* ext-mbstring
* monolog/monolog: ^3.0 — **only** if you use Path A (`ContextEnrichProcessor`, `ExtraProcessor`,
  `RemoteIpProcessor`, `Formatters\*`). Ships in `require-dev` (this repo's own test suite
  exercises both paths) and `suggest`, not `require` — `Logger\EnrichingLogger` (Path B) needs
  nothing but `psr/log`. Verified by an executable no-Monolog install probe (composer `path`
  repository, `--no-dev`, `vendor/monolog` absent) — see `.claude/skills/log-pipeline/SKILL.md`.

# Symfony compatibility

This is a framework-agnostic PSR-3 library, not a bundle, so there is no hard Symfony version
constraint in `composer.json` — the only **hard** runtime dependency is `psr/log`; Monolog 3 is
needed only for Path A (see [Two integration paths](#two-integration-paths) above).

**Verified:** PHP 8.4 and 8.5 are covered by CI. Symfony 6.4 LTS and 7.x are supported;
nothing in `src/` touches a Symfony API, so support is a statement about the wiring examples
below, not about linked code.

**Tracked, not yet verified:** PHP 8.6 runs in CI as a non-gating canary job, and Symfony 8.0
is expected to work unchanged. Both are pinned to card `LSYM-006`, which promotes them to
"verified" once they reach GA.

# Install

`composer require xakki/log-symfony`

# Wiring

There is no DI extension and no `LOG_*` env reading inside this library — you build ONE
`LoggerConfig` (typically via its `fromArray()` factory), then wire the collaborators that
need it as plain services, tag the processor(s) with `monolog.processor`, and reference the
formatters by service id from your handler config.

`config/services.yaml`:

```yaml
parameters:
    # Extra credential-redaction needles, shared between LoggerConfig and Redactor below —
    # a Symfony parameter, not an expression, because plain YAML service config cannot read
    # a property off another service (LoggerConfig is a final readonly class, not an interface).
    log_symfony.redact: ['x_internal_token']

services:
    Xakki\LogSymfony\LoggerConfig:
        factory: [Xakki\LogSymfony\LoggerConfig::class, 'fromArray']
        arguments:
            -   messageLimit: 3024
                allowMemory: false
                extra:
                    app_name: '%env(APP_NAME)%'
                    app_env: '%env(APP_ENV)%'
                    app_ver: '%env(default::APP_VERSION)%'
                    log_ver: !php/const Xakki\LogSymfony\LOGGER_VER
                    tier: '%env(default::APP_TIER)%'
                    release_tag: '%env(default::RELEASE_TAG)%'
                traceExcludedPartials: ['Monolog/', 'xakki/log-symfony/', 'vendor/']
                traceDepth: { warning: 5, error: 10, critical: 20 }
                traceArgLimit: 128
                redact: '%log_symfony.redact%'
                snakeCase: false
                captureHandlers: false

    Xakki\LogSymfony\Redactor:
        arguments:
            $extraNeedles: '%log_symfony.redact%'

    Xakki\LogSymfony\FileTrace:
        arguments:
            $projectDir: '%kernel.project_dir%'

    Xakki\LogSymfony\RequestId: ~

    # Autowires LoggerConfig/Redactor/FileTrace/RequestId from the services above — the
    # collaborator shared by the processor and (if you also use it) EnrichingLogger.
    Xakki\LogSymfony\ContextEnricher: ~

    Xakki\LogSymfony\Processor\ContextEnrichProcessor:
        tags:
            - { name: monolog.processor }

    Xakki\LogSymfony\Processor\ExtraProcessor:
        tags:
            - { name: monolog.processor }

    Xakki\LogSymfony\Processor\RemoteIpProcessor:
        arguments:
            $trustForwardedFor: false  # true only behind a reverse proxy that sets/strips X-Forwarded-For itself
        tags:
            - { name: monolog.processor }

    Xakki\LogSymfony\Formatters\CustomFormatter:
        arguments:
            $dateFormat: 'Y-m-d\TH:i:s.uP'
            $logSize: 1400

    Xakki\LogSymfony\Formatters\ConsoleFormatter: ~

    # Optional: the PSR-3 decorator (see "What you get" — accurate call-site file/trace,
    # one frame from the real caller). Decorates Symfony's default `logger` service; drop
    # this block if ContextEnrichProcessor alone is enough for your app.
    Xakki\LogSymfony\Logger\EnrichingLogger:
        decorates: 'logger'
        arguments:
            $inner: '@.inner'
```

`config/packages/monolog.yaml`:

```yaml
monolog:
    channels: ['app']
    handlers:
        stderr:
            type: stream
            path: "php://stderr"
            level: info
            formatter: Xakki\LogSymfony\Formatters\CustomFormatter
        console:
            type: stream
            path: "php://stdout"
            level: debug
            formatter: Xakki\LogSymfony\Formatters\ConsoleFormatter
            channels: ['!event']
```

Recommended stack for production — `['stderr']` (see [LoggingRules.md §8](./docs/LoggingRules.md)).
`ContextEnrichProcessor`/`ExtraProcessor`/`RemoteIpProcessor` are tagged `monolog.processor`
above with no explicit `handler`/`channel`, so `symfony/monolog-bundle` attaches them
globally, to every handler on every channel — scope a tag's `handler:`/`channel:` attribute
if you only want a processor on one of them.

# Configuration

There is no `config/logger.php` here (this is not a Laravel-style app-config file) —
`Xakki\LogSymfony\LoggerConfig` is a typed, immutable value object; the keys below are its
constructor-promoted properties (build one via `LoggerConfig::fromArray()`, as in
[Wiring](#wiring), or `new LoggerConfig(...)` directly). Same semantics as the LaraLog
reference implementation, renamed from Laravel's `snake_case` config keys to PHP `camelCase`
property names:

| `LoggerConfig` property   | Default                                                                 | What                                                                                                                                         |
|----------------------------|--------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------|
| `messageLimit`             | `3024`                                                                  | max message length kept                                                                                                                      |
| `allowMemory`              | `false`                                                                 | attach `memory_usage` / `memory_peak`                                                                                                        |
| `extra`                    | `[]`  — set `app_name`/`app_env`/`app_ver`/`log_ver`/`tier`/`release_*` yourself, as in the wiring example above | stable per-process fields (§4.2); `ExtraProcessor` copies this whole array onto every record (empty values dropped). Add your own keys here. |
| `traceExcludedPartials`    | `['Monolog/', 'xakki/log-symfony/', 'vendor/']` — verified empirically against a real Monolog call chain (see `tests/Unit/StackDepthTest.php`); LaraLog's `Illuminate/Log/` entry has no Symfony equivalent to swap in — Symfony ships no bundled log-manager layer of its own to exclude | frames stripped from `file`/`trace`                                                          |
| `traceDepth`               | `['warning' => 5, 'error' => 10, 'critical' => 20]`                    | stack-trace frames by level (§3.7)                                                                                                           |
| `traceArgLimit`            | `128`                                                                   | max chars per stringified trace arg                                                                                                          |
| `redact`                   | `[]`                                                                    | extra secret needles, **merged** with the built-in denylist (§2), passed into `Redactor`'s constructor                                       |
| `snakeCase`                | `false`                                                                 | lowercase + snake_case context keys (§4.7); **breaking — opt-in**                                                                            |
| `contextTypeRules`         | `[]`, overlaid on `ContextEnricher::$contextTypeRules` (the built-in table, §4.5) for THIS instance only — never written back to the shared table, so it can't leak into another `ContextEnricher` built with a different `LoggerConfig` | project-level type-coercion rules (see [ContextFieldNaming.md](./docs/ContextFieldNaming.md) §5)                                   |
| `captureHandlers`          | `false`                                                                 | if `true`, call `Xakki\LogSymfony\LogType::installHandlers()` yourself at bootstrap (chained error/exception/shutdown handlers, §4.3.1) — this library never calls it for you |

> **`captureHandlers`** is a plain flag on `LoggerConfig`; installing the actual global PHP
> handlers is a separate explicit call — `LogType::installHandlers()` — because doing it as a
> side effect of building a config object would be a surprising global mutation. Call it once,
> after exercising your error / exception / fatal paths — see [LoggingRules.md §4.3.1](./docs/LoggingRules.md).
> Credential redaction (`redact` + built-ins) is **on by default**; `password`, `token`,
> `api_key`, `authorization`, `cookie`, … are masked to `***` by field name.

# Not yet ported

The following LaraLog features are documented as the reference implementation in
[docs/LoggingRules.md](./docs/LoggingRules.md) but are **not** part of this library yet — planned:

* **SQL query logging** — a slow-query channel with safe binding redaction, via a
  `doctrine/dbal` middleware (LaraLog: `SqlLogServiceProvider`).
* **Messenger heartbeat** — worker heartbeat as a log line, plus a per-message `request_id`
  reset, via a `symfony/messenger` event subscriber (LaraLog: `QueueHeartBeat`).
* **Redis handler** — a capped Redis-backed Monolog handler for async/buffered log writes
  (LaraLog: `RedisLogger`).
* **Laravel `tap` replacements** — LaraLog exposes a few Laravel `tap`-style channel
  customization helpers in its config; no Symfony equivalent is planned yet, use Monolog's
  own handler/processor composition instead.

# See also

* [Logging Rules — full spec](./docs/LoggingRules.md) ([RU](./docs/LoggingRules.ru.md))
