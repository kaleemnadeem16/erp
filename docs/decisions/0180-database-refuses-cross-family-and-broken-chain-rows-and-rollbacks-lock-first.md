[← Decisions](README.md)

# 0180 — The database refuses cross-family and broken-chain rows, and a rollback locks before it looks

- **Status:** accepted
- **Date:** 2026-11-09
- **Affects:** Sales (`quotation_draft_tax_declarations`, `sales_customer_decision_receipts`), Tax (`tax_quotation_source_corrections`), Company (`company_brand_versions`), every migration `down()` that refuses to destroy evidence
- **Builds on:** 0096 (cross-cutting feature standards), `docs/architecture/database.md` migration standards

## Context

Issue #99, independent review section E. Four tables relied on the calling Action alone for a relationship the database could have enforced: a tax declaration named a revision and a logical scope with company-only keys; a customer-decision receipt held a bare case number; a tax source correction's predecessor was checked for company only; and a brand version's logo check passed when a pinned detail was NULL. Separately, twenty-six migrations checked for evidence before taking their exclusive lock, and the brand rollback ignored its own table.

## Decision

1. A declaration carries its family, derived from its revision by the existing insert guard; both references are composite `(company, family, id)` keys.
2. A receipt's `(company, case)` must be an existing case: an insert guard checks it under a key-share lock on the case row (the same lock a foreign key takes). It is a guard, not a foreign key, because the intake foundation is designed to roll back on its own and a key would block that while receipts exist.
3. A correction's predecessor must be a correction of the same fact and exactly one version earlier, checked by an insert trigger that first takes the company's Tax generation row lock; a chain cannot start twice.
4. A logo reference is all-or-nothing: a blob id requires every pinned detail.
5. Constraints added to tables that may hold rows are added `NOT VALID`, then validated only if the existing rows comply; otherwise they guard new rows and the count is logged. Existing evidence is never rewritten (the one back-fill, the derived family, is done under the table lock).
6. Every evidence-refusing `down()` takes `LOCK TABLE … ACCESS EXCLUSIVE` on all tables it reads or drops first; an architecture test enforces it. The brand rollback refuses when either brand versions or branding change history exist.

## Alternatives rejected

- A trigger instead of composite keys for 1: a key is declarative and visible to the tenancy ratchet. A real foreign key for 2: it breaks the independent rollback of the intake foundation (a test proves that property).
- Failing the migration on existing violations: that blocks a deploy over rows nobody may edit; a logged, partially enforced constraint is safer and visible.
- Deleting or repairing offending rows: breaks the append-only evidence rule.

## Consequences

- Raw SQL can no longer create these relationships wrongly; tests attack each with direct inserts.
- The correction trigger serialises corrections per company, which the existing Tax generation bump already did.

## Related

- [database.md](../architecture/database.md) · [domain-integrity.md](../architecture/domain-integrity.md)
