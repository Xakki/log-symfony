### LSYM-002 — Messenger heartbeat + per-message request_id reset

**Criticality:** High

**TAGS:**
- feature

**Description:**
Port two Laravel queue-worker behaviours: `Xakki\LaraLog\QueueHeartBeat` (periodic
"worker alive" log line while a worker idle-polls, plus a line on `WorkerStopping`) and the
`JobProcessing` listener inside `LaraLogServiceProvider` that calls `resetRequestId()`
between jobs.

**Problem:**
`Queue::popUsing()` and the Illuminate queue events do not exist in Symfony. The equivalent
hooks are Messenger's worker events (`WorkerStartedEvent`, `WorkerRunningEvent`,
`WorkerMessageReceivedEvent`, `WorkerStoppedEvent`) via an `EventSubscriberInterface`.

**Impact:**
Two distinct losses, the second is the dangerous one:
1. No heartbeat — a silently wedged worker looks identical to an idle one in Graylog.
2. **`request_id` leaks across messages** — every record a long-lived worker emits keeps the
   id of whichever message happened to run first, so correlation in OpenSearch silently
   attributes unrelated work to one request. `Xakki\LogSymfony\RequestId::reset()` already
   exists for exactly this hook and is currently called by nothing.

**Recommendation:**
- `Xakki\LogSymfony\Messenger\LoggingWorkerSubscriber` — `reset()` on
  `WorkerMessageReceivedEvent`, heartbeat on `WorkerRunningEvent` throttled by an interval
  from `LoggerConfig`, stop line on `WorkerStoppedEvent`.
- `symfony/messenger` → `require-dev` + `suggest` only.

**Acceptance Criteria:**
- Two messages processed in one worker run produce two DIFFERENT `request_id` values.
  This test must be shown to fail when the reset call is removed — a passing test written
  after the fix is the most likely of all to be vacuous.
- The heartbeat respects its interval (N running events within the interval → 1 line).
- Tests/QA green: `make test`.

**Decisions:**
- Port to a Messenger event subscriber — confirmed with @user 2026-09-06.
- `RequestId` is an injectable instance service, not `$_SERVER`+`putenv()` global state as in
  the Laravel package.
