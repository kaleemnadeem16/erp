[← Decisions](README.md)

# 0104 — Separate customer acceptance from operational and financial readiness

- **Status:** accepted — bounded R1-C2 direction, explicitly owner-authorised after consistency review.
- **Date:** 2026-10-09
- **Affects:** Phase 4 acceptance evidence, handover preparation, Parties and Phase 5/6 boundaries.
- **Gate:** documentation only; no implementation/migration; operation-specific gates are deferred.

## Context

ADR 0103 permits eligible Party-backed quotation issue without CustomerAccount. A buyer may
then genuinely accept while Finance setup remains pending. Blocking that commercial fact hides
commitment; treating its entry as approval for work or money processing creates uncontrolled
exposure. The owner reviewed [C2 research](../phases/phase-4-r1-c2-financial-readiness.md) and
approved the bounded separation, explicitly withholding a universal account-at-handover rule.

## Decision

Valid customer acceptance may be recorded before CustomerAccount exists. Acceptance is a
commercial fact, **not automatic authorisation for work, credit or financial processing**.
Handover preparation may proceed while readiness remains outstanding. Financial and operational
authorisation must be checked at the relevant business operations; CustomerAccount existence
alone does not imply credit clearance.

Example: ABC Industries accepts a PKR `500000.0000` quotation. Record valid acceptance with
Finance setup visibly pending. Prepare handover information, while confirmed handover and
financial acts remain subject to applicable readiness controls. Do not silently cancel acceptance
because Finance is delayed or refuses setup; any commercial disposition requires its own evidence
and authority. Account-pending is not zero accepted business.

Reuse existing Party identity and independent financial permissions. No automatic financial
account creation, placeholder terms/mappings, supplier-role substitution or Sales permission
bypass is authorised. Current mandatory account fields and guarded Party Actions remain intact.
Acceptance still requires applicable authoritative customer-decision/offer evidence and scope;
this decision does not certify every inbound message as valid acceptance or waive R6 controls.

**No universal CustomerAccount requirement at every confirmed handover is decided.** Cash,
prepaid and non-credit workflows may justify different operation-specific treatment. R6/R7 must
settle acceptance and operational gates; financial processing, credit, payments, invoices and
postings belong to the appropriate later phase. Preparing a handover is not permission to release
materials/labour or initiate collection. No particular cash/prepaid exemption is approved here.

## Reconciliation and deferred scope

- ADRs 0034/0084 preserve shared Party and independent customer/supplier roles and protected
  financial fields. ADR 0103 still requires eligible recipient/immutable issue evidence.
- ADRs 0100/0101 distinguish authoritative accepted scope from potential, invoice revenue or
  opportunity outcome. ADR 0102's review/trust rule is unchanged; acceptance may require forecast
  review without silently altering maintained estimates.
- Proposed ADRs 0098/0099 remain Proposed. Optional CRM and association edits cannot create
  acceptance, clear financial readiness or modify original commercial evidence.
- Approval, current authority, tenant/record/field scope, immutable evidence and atomic retry
  guarantees remain. Future money-carrying modules still register financial-activity probes.
- Exact validation, partial/corrected acceptance, holds/release and jobs are R6/R7; accounting
  readiness and credit exposure are Phase 6. Capability vocabulary/reporting/history are R9.

Consistency review found no material conflict or additional business choice in this bounded
contract. Whole R1 and Q39 remain open; this ADR does not close all C2 delivery design.

## Consequences

- **Positive:** genuine commitments remain visible while Finance works; duties and readiness
  are explicit without forcing unnecessary early financial onboarding.
- **Negative:** a recorded acceptance cannot serve as the sole gate for downstream work; pending
  readiness requires clear reporting and controlled disposition rather than automatic cancellation.
- **Requires:** separate evidence for acceptance and readiness, current operation-level checks,
  protected financial fields and clear distinction between preparation and authorised execution.

## Alternatives considered

**Require CustomerAccount just to record valid acceptance** — rejected by approved direction;
conflates a commercial fact with administrative completion.
**Acceptance or account existence automatically authorises work/credit** — rejected; neither is clearance.
**Universally require account at confirmed handover** — not accepted; investigate cash/prepaid/
non-credit capabilities under R6/R7 and later financial design.
**Delay every control until invoicing** — not accepted; relevant financial/exposure operations
may precede it and need their own checks.

## Research basis

Reuse [C2 primary comparison](../phases/phase-4-r1-c2-financial-readiness.md#primary-source-comparison),
accessed 2026-10-09: ERPNext customer orders/credit/payment entries, Dynamics prospect conversion/
credit holds and Odoo down-payment invoices. These distinguish commercial, operational and
financial events. They support separation, not a universal conversion trigger for our jobs.
The owner selected this bounded invariant; detailed posting treatment remains later research.

## Related

- [C2 research](../phases/phase-4-r1-c2-financial-readiness.md) · [R1 consolidated review](../phases/phase-4-r1-decision-matrix.md)
- [ADR 0103](0103-issue-quotations-to-party-backed-prospects.md) · [ADR 0101](0101-separate-accepted-business-and-remaining-forecast.md)
- [Parties](../domain/parties.md) · [Sales pipeline](../domain/sales-pipeline.md)
- [Phase 4](../phases/phase-4-sales-quotations-and-handover.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
