[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R2 — Existing capabilities and adoption gaps

**Historical initial inventory:** 2026-10-09, starting from `49ece90`; no Sales runtime at that baseline.
Subsequent CRM/Party backend commits are separate delivery, not quotation/cost-adoption implementation.
**Current direction:** [ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md) replaces the Assembly-only
Sales assumption; [verified impact audit](phase-4-r2-compatibility-audit.md) and
[compatibility plan](phase-4-r2-compatibility-plan.md) extend this initial inventory.
This record answers what quotation structure can reuse, rather than assuming a domain sketch
or a module README's older progress paragraph is executable functionality.

## Implemented foundations

| Area | Verified implementation | Reuse and limitation |
|---|---|---|
| Assemblies | `backend/app/Modules/Estimating/Models/Assembly.php`, `AssemblyVersion.php`, `AssemblyComponent.php`; `Actions/ExplodeAssemblyVersion.php` | Versioned work-unit estimates; MATERIAL, LABOUR, PLANT and SUBCONTRACT cost components, plus overhead. An Assembly needs no output Item. Component kinds do not determine commercial line types. |
| Cost roll-up | `ExplodeAssemblyVersion`, `ResolveResourceRate`, `Support/AssemblyCostBreakdown.php` | Decimal cost, resource hours/rates and overhead evidence. Estimator `sellPerUnit` arithmetic is not the Phase 4 customer pricing engine. |
| Application rates | Estimating application-rate resolution and company-defined dimensions | Work measure and consumption measure remain distinct; context-sensitive resolution must fail when no applicable rate exists. No construction-specific fields belong in the generic quotation contract. |
| Formulations | `Actions/ResolveFormulation.php` and formulation versions | Recursive material requirements and version references; existing customer override uses CustomerAccount identity. Party-backed prospects must not acquire fake accounts to call this resolver. |
| Material preview | `Actions/PreviewAssemblyMaterials.php` | Read-only standard requirements, what-if planned adjustments, stock advice and hand-chosen stock mix. No saved adopted plan, reservation or stock posting. |
| Items | Inventory `Models/Item.php` and `Support/ItemType.php` | RAW, SEMI_FINISHED, FINISHED, SERVICE, LABOUR and PLANT exist. Subcontract is an estimating component kind, not an existing separate Item type or Sales engine. |
| Global units | `backend/app/Support/UomConversionService.php` | Same-dimension decimal conversion at scale 9, HALF_UP; cross-dimension conversion requires item-specific or estimating evidence. |
| Item units/packages | Inventory `ItemQuantityUnit.php`, `ItemPackaging.php`; Purchasing quantity/price-basis actions | Item-specific conversion and fixed package stock content; separate order, stock and priced quantities in Purchasing. Sales adoption is new; a supplier offer is not a Sales price list. |
| Recipient foundation | Parties `Party.php`, `PartyContact.php`, `PartySite.php`, CustomerAccount/CustomerSite relationships | Shared identity and address/contact records exist. Models/resources do not imply complete independent contact/site maintenance surfaces. Account-backed CustomerSite cannot be compulsory for a prospect's execution location. |
| Numbering | Numbering registry/services; Purchasing PO/receipt/return and Inventory scrap adapters | Shared allocation and series evidence are reusable. No Sales quotation adapter exists; identity and allocation timing still need design. |
| Approvals | Shared Approvals engine; Estimating variance/stock-mix subject adapters | Shared policy/routing/authority foundations exist. Estimating subjects explicitly refuse submission until an adopted quotation plan exists. No quotation approval subject exists. |
| Currency and print | Currency/rate APIs; frontend PO print model and shared print styling | Reuse currency foundations and customer projection patterns. No quotation print model, portal, signature/delivery evidence or quotation-specific PDF pipeline exists. |

## Partially implemented or documented-only

**Generic cost resolution:** accepted [ADR 0077](../decisions/0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md)
requires explicit scope, valuation evidence and COSTED/PARTIAL/UNKNOWN outcomes. The existing
`Estimating/Support/IngredientCostResolver.php` is AVCO-specific and returns zero when no cost
pool exists. That is a known adoption gap: absence of cost must not become a trusted zero-cost
quotation estimate. Do not fix it in this research task or impose a universal cost gate on
maintained-price selling; R3/R5 must decide which pricing/control paths require complete cost.

**Material-plan adoption:** ADRs 0054/0058/0072/0076 establish requirements; preview and routing
advice exist, persistence and controlled quotation adoption do not. A preview fingerprint is not
an issued-plan snapshot or proof that all cost dependencies stayed unchanged. Its evidence
includes work/context/materials and conditional variance/inventory information; preserve the
existing protection against leaking inventory through fingerprint changes.

**Version eligibility:** preview defaults to the active Assembly version. An explicitly supplied
version is checked for membership, but that path does not itself require ACTIVE status.
Quotation issue eligibility is consequently a new R5 rule, not inherited from preview success.

**Quantity precision:** `Http/Requests/MaterialPreview/PreviewMaterialRequest.php` permits up to six fractional digits,
while conversion factors and internal global conversion use nine. Money remains decimal(19,4).
A quotation quantity contract must define precision, bounds, valid increments and rounding
explicitly; claiming all existing quantity APIs already accept nine digits would be incorrect.

**Prospect/customer context:** accepted R1 supports eligible Parties before CustomerAccount,
including organisation/individual business direction. This accepted design is not proof that
all corresponding Party-kind, CRM, readiness or quotation APIs already exist.

**Sales, tax and jobs:** Sales pipeline/pricing, tax and job domain records are design inputs.
There are no implemented Sales family/revision/line, selection, acceptance or handover engines.
Do not reproduce their historical pseudo-DDL as the current schema.

## Accepted contracts reused

- ADR 0109 supersedes the universal Assembly selling assumptions of historical 0008/0055.
  Versioned estimates and progressive Sales pricing independent of cost remain valid;
  direct Item/service/manual sources are accepted bounded direction.
- ADRs 0050/0080: distinguish global conversion, item conversion, quantity increments, package
  content and price denominator. Purchasing-specific defaults do not automatically become Sales policy.
- ADRs 0054/0058/0072/0076/0077: governed plan variance and stock choice; preview/adoption boundary;
  cost uncertainty and explicit scope. Existing [Estimating phase](phase-3-estimating-core.md) is reused.
- ADRs 0098/0099: optional pipeline subject to company policy; consistent current family association
  and immutable per-revision issue association.
- ADRs 0100–0102: independent versus competing families; selected issued revisions, no accepted-work
  double count, persistent review and immutable dated forecast FX evidence.
- ADRs 0103–0105: Party-backed issue/acceptance, separate operational/financial readiness and
  controlled identity reuse. Recipient identity is not an implicit credit or work authority.
- ADRs 0106–0108: ownership does not grant visibility; governed outcomes and progressive CRM.
  Configurable Sales teams and ownership visibility are not implemented assumptions.
- Invariants, shared authorisation, audit, approvals and numbering: company isolation, guarded
  Actions, decimal arithmetic, atomic retries and append-only issued evidence remain mandatory.

## What Sales must add after decisions

Family/revision/offer identity; commercial scope and presentation; quotation quantity adapters;
recipient/location snapshots; persisted estimate adoption and complete cost evidence where required;
quotation approval/numbering adapters; customer-safe print projection; eligibility and selection
contracts. No new physical table layout is selected by this inventory.

## Related

- [R2 recommendations](phase-4-r2-quotation-structure.md) · [Primary comparison](phase-4-r2-erp-comparison.md)
- [Sales pipeline](../domain/sales-pipeline.md) · [Assemblies](../domain/assemblies.md)
- [Phase 4 plan](phase-4-sales-quotations-and-handover.md) · [Invariants](../architecture/invariants.md)
