### LSYM-009 — Verify the Readme wiring snippets against a real Symfony app

**Criticality:** Medium

**TAGS:**
- tech-debt

**Description:**
`Readme.md` carries `config/packages/monolog.yaml` and `config/services.yaml` snippets that
wire the processors, formatters and the optional `EnrichingLogger` decorator. They name real
FQCNs and real constructor arguments — but they were written by reading the code, never by
running them. No Symfony application has ever booted them.

**Problem:**
A wiring snippet is the part of a library that consumers copy verbatim, and it is the part with
no test. A wrong service id, a missing tag attribute, a constructor arg in the wrong order or a
`%kernel.project_dir%` that cannot be resolved in that position all produce a container
compile error for the consumer while the library's own suite stays green.

**Impact:**
The first consumer pays the debugging cost, and the failure looks like the library is broken
rather than the documentation. `xakki/convertor` is that first consumer.

**Recommendation:**
- Stand up a throwaway Symfony skeleton under `/var/tmp/backup/log-symfony/` (NOT `/tmp` —
  tmpfs), install the package from a `path` repository, paste each snippet **verbatim** (fixing
  the Readme, never the pasted copy, when something fails), boot the container, emit a log
  record and confirm the enriched context comes out.
- Cover both integration paths separately: the `monolog.processor`-tagged
  `ContextEnrichProcessor`, and the PSR-3 `EnrichingLogger` decorator over a non-Monolog logger.
- A Symfony Flex recipe is explicitly OUT of scope: it needs a PR to `symfony/recipes-contrib`
  or a private flex endpoint, which is not worth it for the current consumer count. A verified
  copy-paste snippet is the deliverable.
- Record on this card what was run and what came out, so the next reader knows the snippets are
  evidence-backed rather than plausible.

**Acceptance Criteria:**
- Both paths booted in a real Symfony app; the emitted record shown on this card.
- Every correction folded back into `Readme.md`.
- Tests/QA green: `make test`.

**Decisions:**
- Raised by the `xakki/convertor` integration review 2026-09-07 as "documented Symfony
  integration recipe or Bundle/Extension". The Bundle half was declined by @user twice
  (2026-09-06, and again 2026-09-07 after the `file-uploader-symfony` precedent was surfaced) —
  this package has no HTTP surface and no commands, so a bundle would buy a DI config tree at
  the cost of a hard Symfony dependency. Only the recipe half is in scope.
