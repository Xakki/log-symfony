### LSYM-006 — PHP 8.6 and Symfony 8.0 GA readiness

**Criticality:** Medium

**TAGS:**
- tech-debt

**Description:**
Both PHP 8.6 and Symfony 8.0 are expected around November 2026 and neither exists as a release
yet. The package should adopt each on its GA day, not months later. This card is the standing
watch item; it is worked when a GA actually lands, not before.

**Problem:**
Two different kinds of "support", easy to conflate:
- **PHP 8.6** — the `composer.json` constraint `^8.4` ALREADY resolves to `>=8.4 <9.0`, so 8.6
  installs the moment it ships, whether or not anything was ever run on it. The constraint is
  not the gap; the *evidence* is. CI carries a non-gating `8.6` canary job for exactly this.
- **Symfony 8.0** — nothing in `src/` links a Symfony API, so there is no code-level risk. The
  exposure is entirely in the wiring examples (`monolog.yaml` / `services.yaml` snippets in
  `Readme.md`) and in the dev-only dependencies the deferred cards will introduce
  (`doctrine/dbal` for LSYM-001, `symfony/messenger` for LSYM-002).

**Impact:**
Consumers running the newest stack (e.g. `xakki/convertor`, already on PHP 8.5 / Symfony 7.4)
get a package that claims compatibility by semver but was never executed on their runtime. The
failure mode is a deprecation or a behaviour change surfacing in production rather than in CI.

**Recommendation:**
On each GA, in one change:
1. **PHP 8.6** — promote the canary out of `continue-on-error` in
   `.github/workflows/autotest.yml` (drop the `include:` entry, add `'8.6'` to the matrix
   list), run `make test PHP_IMAGE=php:8.6-cli-alpine TAG=logsymfony-php:8.6` locally, and move
   8.6 from the "tracked" to the "verified" line in `Readme.md`. Do NOT widen the `php`
   constraint — `^8.4` already covers it; widening it to `^8.4|^8.6` would be noise.
   ⚠ Read the phpunit SUMMARY COUNT line, not a `tail` slice — a truncated view of a failing
   run looks identical to a passing one.
2. **Symfony 8.0** — stand up a throwaway SF 8.0 skeleton under `/var/tmp/backup/log-symfony/`,
   paste the `Readme.md` wiring snippets verbatim, and confirm a log record actually comes out
   with enriched context. The snippets are the deliverable under test, not the library.
3. Update the `suggest` entries and any `require-dev` constraints added by LSYM-001/LSYM-002 to
   `^7.0|^8.0`.

**Acceptance Criteria:**
- `Readme.md` states PHP 8.6 / Symfony 8.0 as verified, with CI proving the PHP half.
- The 8.6 job gates merges (no longer `continue-on-error`).
- The SF 8.0 wiring check is recorded on this card: what was run, what came out.
- Tests/QA green: `make test`.

**Decisions:**
- Adopt both on GA rather than waiting — owner instruction 2026-09-07.
- `php: ^8.4` is the single constraint; it already means `>=8.4 <9.0`. Confirmed by
  execution 2026-09-07: a path-repo install of this package under `php:8.5-cli-alpine`
  resolved cleanly, and `make test` on PHP 8.5 passed the full suite.
- PHP 8.3 dropped 2026-09-07 (@user). It pulled PHPUnit 12 while 8.4+ pull PHPUnit 13, so the
  suite ran under two different runners and a PHPUnit-13 deprecation was invisible on 8.3.
  One runner across the whole matrix is the point of the drop.
