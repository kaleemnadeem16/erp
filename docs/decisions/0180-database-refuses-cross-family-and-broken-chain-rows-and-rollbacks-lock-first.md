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

## Addendum (issue #107): historical violations are counted, reported and validated explicitly

Decision 5 accepted pre-existing violating rows with only a log line. That is now complete, still without touching any data:

- **Counted and recorded.** Migration `2026_12_10_340000_record_historical_integrity_violations` stores, per rule, how many existing rows violated it when it was introduced (`integrity_rule_baselines`: counts and timestamps only) and logs a warning for each non-zero count. The rules and their read-only counting queries live in `App\Support\Integrity\HistoricalViolationRegistry`: the declaration scope key, the brand logo check, the receipt case guard and the correction chain guard. (The unique index of migration 100000 cannot be created over duplicates, so it never accepts a violating row and is not listed.)
- **Reported.** `php artisan integrity:historical-violations [--ids] [--limit=N] [--json]` is read-only (a read-only snapshot), shows the count at introduction and now per rule, and exits non-zero while any violation remains. Output is counts and, with `--ids`, row ids; never row content.
- **Validated explicitly.** `php artisan integrity:validate-constraints [rule]` runs `VALIDATE CONSTRAINT` for a NOT VALID constraint only when its count is zero, and refuses (non-zero exit) otherwise. Insert guards (triggers) never re-judge old rows, so there is nothing to validate for them; they stay report-only.
- **Never auto-fixed.** Nothing deletes or repairs a row. Several of these tables are append-only evidence, so clearing a violation is a reviewed, owner-approved procedure outside these commands; until then the rule keeps guarding new writes, which existing tests prove.

## Related

- [database.md](../architecture/database.md) · [domain-integrity.md](../architecture/domain-integrity.md)
