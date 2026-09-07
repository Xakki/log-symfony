### LSYM-007 — Typed context must survive the formatter into JSON

**Criticality:** High

**TAGS:**
- test-coverage

**Description:**
Every existing test asserts on a `Monolog\LogRecord` via `TestHandler` — i.e. on the record
*before* a formatter touches it. Nothing in the suite runs a record through `CustomFormatter`
(or any formatter) and inspects the resulting JSON string.

**Problem:**
The whole point of `ContextEnricher::contextTypeCorrector()` is that a field lands in
OpenSearch with the right TYPE. That guarantee is only worth something at the far end of the
pipe. Between "the corrector set `count` to int 5" and "Graylog received `\"count\":5`" there
is currently no test boundary at all: `NormalizerFormatter` normalization, the
`JSON_UNESCAPED_UNICODE` encode, and `CustomFormatter`'s iterative size-trimming all run
untested against typed values.

**Impact:**
A field-type mismatch does not throw and does not log — OpenSearch **silently drops the whole
document**. The failure is invisible from inside the app: logs simply stop arriving for the
affected records, and the shared index is fed by several services, so the blast radius is not
limited to one project. This is the single highest-value untested boundary in the package.

**Recommendation:**
- Drive a real `Monolog\Logger` → `StreamHandler` over `php://memory` (or a `TestHandler` whose
  formatter is set explicitly) → `CustomFormatter`, then `json_decode` the emitted line and
  assert on PHP types, not on string content: `assertIsInt`, `assertIsFloat`, `assertIsBool`,
  `assertIsString`.
- Cover at least one field per rule kind (suffix / reserved / exact / word / prefix) plus the
  known id-trap cases, so the test fails if a rule stops being applied.
- Cover `CustomFormatter`'s size budget: a record that exceeds `logSize` must still come out as
  parseable JSON after trimming, not as a truncated broken string. That trimming is iterative
  and is the most likely place for a silent corruption.
- Do the same for the `ConsoleFormatter` path only insofar as it must not crash on typed values.

**Acceptance Criteria:**
- For each rule kind, a decoded-JSON assertion on the PHP type of the value.
- An over-budget record decodes as valid JSON.
- Each new test proven able to fail: break the corrector (or the formatter) and show the RED
  output before counting the test as done. A test written right after the code it covers is the
  most likely of all to be vacuous.
- Tests/QA green: `make test`.

**Decisions:**
- Raised by the `xakki/convertor` integration review 2026-09-07 as "integration test:
  LoggerInterface → JSON stdout/stderr with typed context"; accepted as the highest-priority
  item of that list.
