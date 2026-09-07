### LSYM-005 — Verify LogType global handlers vs Symfony ErrorHandler ordering

**Criticality:** Medium

**TAGS:**
- tech-debt

**Description:**
`Xakki\LogSymfony\LogType::installHandlers()` chains `set_error_handler` /
`set_exception_handler` / `register_shutdown_function` on top of whatever the framework
already registered, so a record can be classified `trigger` / `exception` / `fatal` rather
than plain `logger`. It is ported and opt-in (`captureHandlers`, default false), but its
behaviour under Symfony's own `ErrorHandler`/`DebugClassLoader` bootstrap has NOT been
verified — only under Laravel's.

**Problem:**
Symfony centralises error handling far more than Laravel: `symfony/error-handler` installs
its own handlers early in the kernel boot, and `HttpKernel` converts most throwables into
kernel exception events before any global handler runs. The log-laravel docs already record
a caveat that a fatal may reach the logger through the exception path before the shutdown
function fires — under Symfony the ordering is different again and the caveat may be wrong,
not just imprecise.

**Impact:**
`log_type` is a load-bearing field in the shared Graylog schema (see `docs/LoggingRules.md`).
A wrong value is worse than a missing one: dashboards filtering `log_type:fatal` would show
nothing while fatals are happening, and nobody would notice.

**Recommendation:**
Settle it by execution, not by reading:
- Build a throwaway minimal Symfony app under `/tmp/backup/log-symfony/`, install the package,
  enable `captureHandlers`, then actually trigger each of: a `trigger_error` notice, an
  uncaught exception in a controller, an uncaught exception in a console command, and a real
  fatal (memory exhaustion or a call to an undefined method on null).
- Record the observed `log_type` for each of the four, in both `dev` and `prod` env, HTTP and
  CLI.
- If a case is misclassified: either fix the ordering, or document the limitation explicitly
  in Readme and `docs/LoggingRules.md`. Do NOT leave the Laravel-derived caveat text standing
  unverified — a doc claim carried over from another framework is drift, not documentation.

**Acceptance Criteria:**
- A results table (4 cases x 2 envs x HTTP/CLI) recorded on this card.
- Readme states which `log_type` values are reliable under Symfony and which are not.
- Tests/QA green: `make test`.
