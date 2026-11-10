[← Decisions](README.md)

# 0170 — Accept the missing exact-SHA CI evidence for the demo operator bootstrap

- **Status:** accepted (owner decision, 2026-11-10)
- **Date:** 2026-11-10
- **Affects:** the review gate of [0155](0155-bootstrap-only-the-synthetic-demo-access-operator.md); [0156](0156-quotation-demo-access-and-activation-boundaries.md)

## Context

[0155](0155-bootstrap-only-the-synthetic-demo-access-operator.md) requires exact-SHA review and CI for the bootstrap implementation (commit `c34821b6`) before integration. An independent
review found no discoverable CI run for that exact commit: it was integrated together with neighbouring work and later commits, and the only runs are for descendants. The same review also
found defects in the bootstrap itself (a repeatable create-once receipt, grants outside the operator ceiling, authority checked before the protected write). They were fixed in `f9fbd424`.
0155 is accepted and immutable, so the exception is recorded here rather than by editing it.

## Decision

The owner accepts, as an explicit exception to the 0155 gate, that no CI run exists for the exact SHA `c34821b6`.

- The accepted evidence is the corrected implementation: the fixes in `f9fbd424` are verified by CI on `develop` (a descendant of `f9fbd424`; Pipeline run 42778734069 for `develop`
  at `73986e0d`, conclusion success), so the shipped bootstrap behaviour, not the first commit, is what CI has proven.
- The exception covers the missing run only. Every other 0155 condition stands: the exact allowlist, environment/company/identity refusal, append-only receipt, no route or screen, no
  activation of a duty, production blocked. The independent review's other findings are tracked and fixed on their own.
- It is not a precedent: a new exception needs its own decision.

## Alternatives rejected

- **Re-run CI on the historical SHA.** The commit is superseded; a run on it would prove code that no longer ships.
- **Rewrite history to give the commit its own run.** Forbidden on a shared branch and pointless for the same reason.
- **Leave the gate open.** It would block the demo access work on evidence that cannot be produced and adds no assurance over the CI-verified fixes.

## Consequences

- **Positive:** the 0155 gate is closed on a recorded, owner-approved basis.
- **Negative:** the first bootstrap commit has no CI run of its own; the record is this ADR.

## Related

[0155](0155-bootstrap-only-the-synthetic-demo-access-operator.md) · [0156](0156-quotation-demo-access-and-activation-boundaries.md) · [domain: quotation-access-demo](../domain/quotation-access-demo.md)
