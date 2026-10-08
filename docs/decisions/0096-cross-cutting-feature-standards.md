# 0096 — Cross-cutting feature standards live in canonical docs and a completion checklist

- **Status:** accepted (2026-10-08)
- **Date:** 2026-10-08
- **Affects:** AGENTS.md, docs/architecture/ (new and existing standards), every future feature

## Context

Security and scope (the 2026-09/10 access audit and retrofit), UI consistency (the ERP Blue standard and the phone-width and contrast fixes), integrity (fail-closed writers, idempotency, evidence) and
rollout (grants for new permissions) were each learned *after* a feature shipped and then retrofitted: the same kinds of gap — an HTTP-only guard, an unscoped list, a screen that overflowed on a phone, a
permission nobody was granted, a stale status — were found repeatedly. The conventions existed, but spread over ADRs, one audit tracker, one checklist per area and the memory of whoever did the work. The owner
asked (2026-10-08) for these to be codified so a new feature meets them in its first commit, and asked that the result not be one enormous checklist agents would ignore.

## Decision

1. **Detail lives in one canonical document per concern; nothing is duplicated.** Security and scope: [authorization.md](../architecture/authorization.md) (R1–R14). API and refusal codes:
   [api-conventions.md](../architecture/api-conventions.md). Integrity of write operations: [domain-integrity.md](../architecture/domain-integrity.md). Migrations and database invariants:
   [database.md](../architecture/database.md#migration-standards). Screens and workflow behaviour: [erp-blue-design-standard.md](../architecture/erp-blue-design-standard.md). Tests:
   [testing-standards.md](../architecture/testing-standards.md). Demo data: [environments.md](../architecture/environments.md#development-demo-data). Documentation: AGENTS.md's rules and the table in the checklist.
2. **One short index ties them together:** [feature-completion-checklist.md](../architecture/feature-completion-checklist.md) — fourteen questions, each answered "yes", "not applicable (one line)" or
   by doing it, each pointing at the document that holds the detail. `AGENTS.md` carries only the short mandatory rules and the link.
3. **The standards are an applicability checklist, not a quota.** A concern is required when the feature touches it; the test and documentation expectations say so per row. Where the repository already had a
   convention it was codified as written (R1–R11, the ratchet tests, the demo-data rules, ADRs 0066/0078/0091/0095), not replaced.
4. **Ratchet tests are the enforcement for what can be machine-checked** (write-route guards, open reads, record-scope coverage, the stock-writer matrix, company-scoped keys). A feature that adds what a ratchet
   watches registers it in the same change. Rules that cannot be machine-checked (UI hierarchy, language) are enforced in review through the checklist.
5. **New or changed standards go through the same route as any decision:** a real change of rule is an ADR; the canonical document is then edited and the old text marked historical, never left to contradict it.

## Consequences

- **Positive:** an agent has one entry point and a short list; the detailed rules sit where an agent already looks for that topic; gaps found later are added to one place.
- **Negative:** more documents to keep consistent; a checklist can still be answered carelessly — reviews and the ratchets are the backstop.
- **Requires:** `AGENTS.md`, the architecture and docs indexes link the new documents; later audits update the canonical doc, not a copy.

## Alternatives considered

**One large standards document** — rejected: it would exceed the one-topic-per-file rule, and agents skim a long list; topic documents load only what a task needs.
**Only AGENTS.md rules** — rejected: AGENTS.md is read every session and must stay short; the detail needs a home that is not loaded routinely.
**A checklist with every test and doc mandatory for every feature** — rejected: it makes real concerns indistinguishable from irrelevant ones; applicability keeps the signal.
**Leave conventions implicit in the audit tracker and ADRs** — rejected: that is the status quo that produced repeated retrofits.

## Related

- [feature-completion-checklist.md](../architecture/feature-completion-checklist.md) · [testing-standards.md](../architecture/testing-standards.md) · [domain-integrity.md](../architecture/domain-integrity.md)
- [ADR 0095](0095-status-is-short-current-and-purpose-limited.md) · [ADR 0091](0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md) · [ADR 0066](0066-capability-driven-screen-navigation.md)
- [AGENTS.md](../../AGENTS.md)
