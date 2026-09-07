### LSYM-004 — Replacements for the Laravel `tap` mechanism

**Criticality:** Minor

**TAGS:**
- tech-debt

**Description:**
Laravel's `config/logging.php` supports `'tap' => [SomeClass::class]` — invokables handed the
built Monolog `Logger` to post-process it. Three log-laravel classes are implementations of
that contract: `Tap/NoContext` (calls `withoutContext()`), `Formatters/CustomizeFormatter`
(force-overwrites every handler's formatter), and the shared-context part of `BaseContext`.

**Problem:**
symfony/monolog-bundle has no `tap` concept. The closest equivalents are a compiler pass over
the handler services, or expressing the same intent declaratively in `monolog.yaml`.

**Impact:**
Low. `BaseContext`'s useful half (remote IP) is already ported as
`Processor\RemoteIpProcessor`. What remains is mostly configuration ergonomics — most `tap`
uses are expressible directly in `monolog.yaml` and may need no code at all.

**Recommendation:**
Decide per class, and prefer documentation over code:
- `NoContext` — likely unnecessary; Monolog's per-logger shared context is opt-in in Symfony.
- `CustomizeFormatter` — replaced by a `formatter:` key on each handler in `monolog.yaml`.
- If any case genuinely needs code, a `DependencyInjection\Compiler\` pass ships in a
  separate optional namespace and stays out of `require`.

**Acceptance Criteria:**
- Each of the three has a written verdict: replaced by YAML (with the snippet in Readme),
  ported as code, or dropped with the reason.
- Tests/QA green: `make test` (if any code lands).
