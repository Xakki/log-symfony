### LSYM-001 — Doctrine DBAL SQL query logging middleware

**Criticality:** Medium

**TAGS:**
- feature

**Description:**
Port `Xakki\LaraLog\SqlLogServiceProvider` (`/home/xakki/log-laravel/src/SqlLogServiceProvider.php`)
to Symfony/Doctrine. The Laravel version subscribes to `DB::listen()` /
`Illuminate\Database\Events\QueryExecuted`, classifies the statement (`sql_type`, `table`) with a
regex, applies per-type slow-query thresholds and logs matching queries with (capped) bindings.

**Problem:**
`DB::listen()` has no Symfony equivalent. Doctrine DBAL exposes a
`Doctrine\DBAL\Driver\Middleware` chain (`Middleware`/`Driver`/`Connection`/`Statement`
decorators) — the SQL text, params and elapsed time have to be captured there instead.

**Impact:**
Symfony services get no slow-query visibility in Graylog; the two packages produce different
field sets for the same OpenSearch index, so cross-service SQL dashboards break.

**Recommendation:**
- New optional namespace `Xakki\LogSymfony\Doctrine\` with a `SqlLoggingMiddleware`
  implementing `Doctrine\DBAL\Driver\Middleware`, wired by the host app in
  `doctrine.dbal.middlewares`.
- `doctrine/dbal` goes to `require-dev` + `suggest`, never `require` — this package stays a pure
  Monolog library (see CLAUDE.md).
- The regex-based SQL type/table classification in the Laravel source is DB-layer-agnostic:
  port it verbatim so `sql_type`/`table` values match byte-for-byte across both packages.
- Thresholds (`sqlSlowLogAll`, `sqlSlowLogForSelect`) move into the typed `LoggerConfig`
  object, not `config()`/`env()`.

**Acceptance Criteria:**
- A slow query emits one log record with the same field names/types as the Laravel package.
- A fast query below the threshold emits nothing.
- Bindings are redacted through `Redactor` and capped, same as the Laravel version.
- Tests use a real DBAL connection over an in-memory SQLite driver — not a mock of the
  middleware chain (a mock cannot prove the decorator is actually invoked).
- Tests/QA green: `make test` (cs-check + phpstan lvl 8 + phpunit).

**Decisions:**
- Port to a Doctrine DBAL middleware — confirmed with @user 2026-09-06, together with the
  decision to keep the package framework-free.
- Target **DBAL 4**, not 3.x: the first consumer, `xakki/convertor`, is on `doctrine/dbal 4.4.3`
  (verified in its `composer.lock` 2026-09-07). The middleware interface exists in both majors
  but the decorator surface differs — write against 4 and state 3.x support only if tested.
