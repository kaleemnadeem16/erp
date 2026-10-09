[← Decisions](README.md)

# 0103 — Issue quotations to eligible Parties without requiring financial customer setup

- **Status:** accepted — owner-approved bounded R1-C1 direction; consistency review passed.
- **Date:** 2026-10-09
- **Affects:** Phase 4 recipient readiness, Parties, issued quotation evidence and R1-C2 boundaries.
- **Gate:** research/documentation only; physical quotation schema remains R2; no implementation.

## Context

A prospect may need an offer before becoming a financial customer. ABC Industries requests a
quotation for PKR `500000.0000`; Sales knows its identity, contact and relevant site but Finance
has not configured terms or receivables mapping. The existing shared Party can exist without
CustomerAccount and can already have a supplier role. Requiring financial setup for every offer
creates unnecessary onboarding and pressure to fabricate fields or extend Sales authority.

The owner approved Party-backed prospect issue after reviewing the [C1 research](../phases/phase-4-r1-c1-prospect-quotations.md)
and expressly authorised acceptance after architectural reconciliation.

## Decision

An official quotation may be issued to an **eligible, company-scoped Party without CustomerAccount**.
Reuse that identity and existing independent customer/supplier role relationships; do not introduce
a parallel prospect/customer master. Later authorised financial setup attaches CustomerAccount to
the same Party when the appropriate downstream event requires it; that event remains R1-C2.

Before issue, validate recipient identity and relevant contact, site and delivery information.
Email or postal address is not universally mandatory: requirements depend on the chosen issuance
method, commercial requirements and applicable law. A Party row/name alone does not establish
readiness. Exact eligibility, recipient checklist and person/site rules remain R1-C3/C4/R4/R5/R8;
this decision does not authorise issue to an ineligible or archived Party through an exception.

Copy the recipient identity and relevant contact/address details into immutable issued quotation
evidence. Later Party edits, account creation, conversion or contact corrections cannot rewrite
it. Genuine corrections to the issued offer require the appropriate revision/correction decision,
not silent refresh from master data or a current CRM association change.

Preserve CustomerAccount's required payment terms, receivable mapping and financial permissions.
Sales quotation authority does not imply account creation/editing, financial-field visibility or
Finance bypass. Reuse appropriate Party Actions with their existing authority and tenant guards.
Identity setup by another authorised user remains possible; no hardcoded role names or broad
permission grants are chosen here.

Duplicates, archived identities/restoration, authorisation and concurrent issue/identity changes
need their owning research packages. They do not justify inventing a new master-data workflow.
Issue must validate current eligible identity/authority and copy consistent evidence atomically;
identical retries cannot duplicate issue, and failure cannot create false successful evidence.
Physical recipient keys, tables, snapshot shape and locks remain R2/R5/R9.

## Reconciliation

- Compatible with accepted ADR 0034, which preserves ADR 0030's unified identity and independent
  role accounts while correcting child scope. Role-specific financial fields stay on the account.
- Compatible with ADR 0084: account creation still requires protected field authority. No financial
  validation is weakened, no dummy mapping or terms supplied and no automatic account conversion.
- Proposed ADRs 0098/0099 remain Proposed. Recipient readiness is independent of their optional
  Lead/configurable Opportunity controls and issue/current CRM separation. No bypass is granted.
- Accepted ADRs 0100–0102 preserve independent accepted scope, authoritative acceptance and
  forecast evidence; allowing prospect issue does not approve acceptance, jobs or financial acts.
- Snapshot, company isolation, Request/Action guards, audit and retry invariants remain. Monetary
  Sales records must register ADR 0081's financial-activity probe regardless of prospect status.
- The historical Sales schema's mandatory customer recipient is a documented-only sketch, not
  accepted runtime behaviour. R2 must reconcile it; no accepted ADR is superseded.

No substantive architectural conflict or new business choice changes the owner's approved meaning.
Whole R1 and Q39 remain open; detailed readiness and all R1-C2 gates are not accepted by this ADR.

## Consequences

- **Positive:** prospects receive controlled offers without unnecessary financial onboarding;
  identity continuity supports supplier/customer dual roles and later conversion.
- **Negative:** recipient readiness and issued evidence cannot rely on account existence; governed
  contact/site surfaces and later financial-readiness gates are required before delivery.
- **Requires:** immutable recipient evidence, scoped identity selection, current authority and
  consistency checks, and separate financial onboarding through existing guarded Actions.

## Alternatives considered

**CustomerAccount before every issue** — rejected: forces Finance setup for uncertain prospects
without proving recipient accuracy or providing immutable evidence.
**Official issue to an unqualified Lead alone** — rejected as a substitute for eligible Party
identity. A lead-originated draft can still be resolved to Party before issue; draft minima remain R2/R5.
**A separate prospect master** — rejected: existing Party already supports independent roles.
**Automatic account creation with defaults** — rejected: would undermine financial authority and
required setup. Neither issue nor conversion grants that authority.

## Research basis

Reuse [C1 comparison](../phases/phase-4-r1-c1-prospect-quotations.md#primary-source-comparison),
accessed 2026-10-09: ERPNext Lead quotations, Dynamics prospect-to-cash and Odoo contact-backed
quotations distinguish identity from later commerce. ERPNext permits offers before customer
creation; Dynamics conversion accompanies its order stage; Odoo shares contact/address foundations.
These differences support our Party-based approach without selecting their conversion defaults or
our later Finance gate. Vendor live links do not replace our immutable issue-time evidence rule.

## Related

- [R1-C1](../phases/phase-4-r1-c1-prospect-quotations.md) · [R1-C2](../phases/phase-4-r1-c2-financial-readiness.md)
- [Parties](../domain/parties.md) · [Sales pipeline](../domain/sales-pipeline.md)
- [ADR 0034](0034-party-child-tables-inherit-company-scope.md) · [ADR 0084](0084-separate-financial-master-data-fields.md)
- [ADR 0098](0098-hybrid-sales-opportunity-requirement.md) · [ADR 0099](0099-separate-quotation-issue-and-crm-association.md)
- [Phase 4](../phases/phase-4-sales-quotations-and-handover.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
