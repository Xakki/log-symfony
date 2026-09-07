### LSYM-003 — Redis Monolog handler factory

**Criticality:** Minor

**TAGS:**
- feature

**Description:**
Port `Xakki\LaraLog\Drivers\RedisLogger` — a Monolog `RedisHandler` over a capped Redis
list, formatted with `LogstashFormatter`.

**Problem:**
The Laravel source implements Laravel's custom-driver contract
(`'driver' => 'custom', 'via' => Class::class`, `__invoke(array $config): LoggerInterface`)
and resolves the connection through the `Redis` facade. Neither exists in Symfony; monolog-bundle
configures handlers declaratively and has no `service`-returning-a-Logger hook of that shape.

**Impact:**
Low — the Redis channel is an optional transport; stdout/stderr and syslog-UDP cover the
main path. Filed so the feature is not silently lost.

**Recommendation:**
- A plain factory service `Xakki\LogSymfony\Handler\RedisHandlerFactory` returning a
  configured `Monolog\Handler\RedisHandler`, referenced from `monolog.yaml` as a
  `type: service` handler.
- Take a `\Redis`/`Predis\Client` instance by constructor injection — do NOT depend on
  `snc/redis-bundle`.
- Preserve the list cap semantics (`REDIS_LOG_CAP_SIZE`) and `LogstashFormatter` so records
  land in the same shape as the Laravel package.

**Acceptance Criteria:**
- Handler writes to the configured key and the list length stays at the cap.
- Readme documents the `monolog.yaml` snippet.
- Tests/QA green: `make test`.
