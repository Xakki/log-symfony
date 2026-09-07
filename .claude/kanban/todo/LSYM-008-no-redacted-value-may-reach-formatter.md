### LSYM-008 — No redacted value may reach formatter output

**Criticality:** High

**TAGS:**
- test-coverage

**Description:**
`RedactorTest` exercises `Redactor` in isolation: given this key or this string, is the value
masked. Nothing asserts the property that actually matters — that a secret placed anywhere in
a log call cannot appear in the bytes the handler writes.

**Problem:**
Redaction is applied at specific points (`contextTypeCorrector` for keyed context, `FileTrace`
for stack-trace args). A secret can enter a record by other routes that no test covers:
- inside `extra` (`ExtraProcessor` fields, `remote_ip`) rather than `context`;
- nested deeper than the corrector walks — an array inside an array inside a context value;
- inside an exception message or a `__toString()` of an object put in context;
- inside a stack-trace argument that `traceArgLimit` truncates rather than masks;
- in a key whose NAME does not match a needle but whose VALUE is obviously a token.

**Impact:**
A leak here writes a live credential into Graylog/OpenSearch, where it is indexed, retained,
and visible to everyone with log access — and it cannot be recalled. Unlike a type mismatch,
this failure is silent in the opposite direction: everything looks like it is working.

**Recommendation:**
- Write a property-style negative test: build one record that carries the SAME sentinel secret
  in every route listed above at once, run it through the full chain to the formatter's output
  string, and assert the sentinel does not occur anywhere in that string.
- Use a sentinel that cannot occur by accident and would not be produced by masking itself.
- ⚠ **This test is worthless unless it can fail.** A negative assertion ("the string is not
  there") passes trivially when the harness is wrong — a typo in the sentinel, a record that
  never reached the formatter, an exception swallowed earlier. Before counting it done: disable
  the `Redactor` (or pass an empty needle list) and confirm the test goes RED for EACH route,
  not just the first. A route that stays green with redaction off is a route the test never
  actually exercised.
- Where a route genuinely cannot be covered by the current design, say so on this card and
  document the limitation in `Readme.md` rather than leaving a false sense of coverage. The
  known Unicode-homoglyph gap is already documented in `Redactor` — extend that note, do not
  silently widen the claim.

**Acceptance Criteria:**
- One test per leak route, each individually proven to go RED with redaction disabled.
- Any uncoverable route named on this card and documented in `Readme.md`.
- Tests/QA green: `make test`.

**Decisions:**
- Raised by the `xakki/convertor` integration review 2026-09-07 ("check that redaction does not
  let forbidden fields into formatter output"); accepted.
