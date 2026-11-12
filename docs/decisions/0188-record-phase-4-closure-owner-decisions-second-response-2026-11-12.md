# 0188 — Record Phase 4 closure owner decisions, second response (2026-11-12)

- **Status:** accepted (owner decisions 2026-11-12)
- **Date:** 2026-11-12
- **Affects:** Phase 4 closure; #107 integrity migration, Sales customer messages, discount authority (#86), Approvals, multi-company readiness; supplements [0187](0187-record-phase-4-closure-decisions-d1-d16.md), amends (in place, still proposed) [0185](0185-configure-company-business-values-and-fix-technical-invariants.md)

## Context

The owner gave a second response on 2026-11-12 to the Phase 4 closure review. This ADR records it concisely. It authorises no production release, no permission activation and no code beyond what each item names.

## Decision

- **#107 migration.** Keep the corrected original migration plus the separate forward correction (`2026_12_12_200000`), provided independent review R2 confirms BOTH the fresh-install and the already-migrated paths. The forward migration must NOT be applied to the development database until R2 passes and the operation is explicitly authorised with an appropriate backup.
- **ADR 0185 amended in place (still proposed).** Mandatory maker/checker separation for internal approvals is never configurable and never weakened. Optional independent verification of genuine external customer acceptance is a per-company business value, default OFF, server-enforced when ON, design-only and inactive (D4; issue #114).
- **Customer Messages record access.** Preserve the shared customer-message inbox, but company-wide visibility must be explicitly authorised through the case-view permission model (`sales.customer-decision.view`), not inferred from unrelated quotation permissions. Users with limited access must not discover protected quotation identities, commercial details, linked-component information or sensitive counts through the list, filters, pagination or error responses. Review R5 decides whether the current implementation meets this and, if not, returns the smallest safe fix.
- **Discount-authority expiry.** The implementation applies a hidden 14-day maximum (`QuotationAuthorityApproval`, `min(14, policy)`) although the policy accepts larger values. That contradicts the decision that 14 calendar days is an initial default, not a permanent hard cap. R7/R8 verify this and recommend a correction before #86 closes. The effective company-approved expiry stays bounded by quotation validity. The system must never silently accept one configured value and apply another.
- **Internal approval separation.** Tests must show that `allow_submitter`, `negative_stock_allow_self_review` and related settings cannot bypass maker/checker where independent approval is mandatory. Legitimate configurable workflows are distinguished from invariant violations; contradictions are escalated rather than silently changing unrelated modules.
- **Multi-company.** The system is single-company operational with multi-company-oriented schema foundations; it must not be described as operationally multi-company. A dedicated multi-company activation/readiness gate is required before any real multi-company operation ([readiness gate](../architecture/multi-company-readiness.md)). It is not a Phase 4 development-closure blocker but is a hard blocker to enabling multi-company operation.
- **Boundary unchanged.** Phase 4 closes after actual acceptance and review gates. Sales-to-Jobs release and the Jobs receiver stay Phase 5. Sensitive duties stay STAGED and ungranted. Production release is controlled separately. R10/R11 browser and accessibility checks are NOT RUN until performed. The complete backend regression required for #86 and the affected security reviews must not be skipped.

## Consequences

- **Positive:** #107, R5 and the expiry cap each have a stated pass condition; the multi-company claim is bounded.
- **Negative:** the forward migration stays unapplied locally until R2 and an authorised backup; discount expiry and Customer Messages may need small fixes before #86 closes.

## Related

- [0187](0187-record-phase-4-closure-decisions-d1-d16.md), [0185](0185-configure-company-business-values-and-fix-technical-invariants.md), [Decision index](README.md)
