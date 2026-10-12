[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R3 — Expanded pricing evidence and architecture reconciliation

**Accessed/reconciled 2026-10-12 · RESEARCH, NOT ACCEPTED DESIGN.** The
[revised P1–P8 decision matrix](phase-4-r3-business-review.md) is the single current owner-review
agenda. This file records its evidence and structural seams, not a second decision register.
Previous [initial comparison](phase-4-r3-sales-pricing.md) remains historical research input.

## Questions researched

How can overlapping customer eligibility, existing category ancestry, pack-specific tiers, ordinary
seller discounts and promotions remain deterministic without requiring enterprise configuration?
Which facts must be retained so later acceptance, credits or rebates do not force a pricing rewrite?
Reuse settled cost/Party/quotation rules; research only unresolved eligibility, combination and
commercial-control semantics. Compare ERPNext, Odoo 19 and Dynamics using their primary sources.

## Current repository findings

| Existing contract / inspected surface | What exists or was accepted | Consequence for proposal |
|---|---|---|
| [Parties](../domain/parties.md), Party/CustomerAccount models; 0030, 0103/0105 | Company-scoped identity, ORGANISATION/INDIVIDUAL kind, contacts/sites, optional customer financial account with credit/payment/account/currency facts. No reviewed first-class commercial classification or multiple pricing-group membership model. | Attach eligible commercial audience to Party, not debtor account, Lead or exclusive identity kind. Prospect quotation needs no account; customer financial readiness remains later independent duty. |
| [Items](../domain/items.md), Inventory Item/Category models | Item has one `category_id`; categories have `parent_id`, company-scoped references and application cycle prevention; existing account/valuation defaults walk ancestry. SERVICE is an Item; no separate Item Group master found in these surfaces. | Reuse category tree, no duplicate taxonomy. Pricing inheritance is independently configured: existing valuation resolves once at creation, so do not copy that temporal rule into dynamic price eligibility. Category pricing rules need explicit applicability, not automatic authority from classification. |
| [Units](../domain/units-of-measure.md), 0050, [packages](../domain/inventory-packages.md), 0080 | Global dimension conversion; item alternate-unit factors; named fixed package identity/direct stock factor, immutable used content; package and stock increments separate from supplier MOQ/multiple. | Preserve offered/price/stock basis and exact factors. Pricing tier declares its own measure and package eligibility. Supplier commercial constraints cannot silently become Sales constraints. Technical recipes stay on global UoM conversion. |
| Estimating Assembly/AssemblyGroup/policy Actions; [0055](../decisions/0055-progressive-sales-pricing-and-controlled-formulas.md) | Existing work grouping, overhead, rate/cost roll-up and transitional sell guidance; progressive Sales design but no final Sales resolver. | Assembly-specific hierarchy only on Assembly scope; no markup on already marked-up guidance. Do not price Assembly from component Item categories. |
| [0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md), R1/R2 accepted closure | Direct Items/services, Assembly and manual lines; independent logical scope/choices; Party-backed prospects; issue snapshots. | Common effect/applicability/result contract across sources. Accountless prospects, alternative scope and no-double-charge remain invariant. |
| [0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md), local C1/C2 status | Authoritative typed cost with complete/partial/unknown; current material evidence, not arbitrary historical cost; blind evaluation can work without operand read. | Cost-derived price requires complete applicable cost; maintained/manual can be independent. Current query cannot fabricate historical/future valuation for future price dates. R5 adoption is still a gate. |
| [Authorisation](../architecture/authorization.md), [domain integrity](../architecture/domain-integrity.md) | Company + duty + effective scope at Request and Action; sensitive fields; stable transactions/locks/idempotency, append-only evidence. Generic audit is not guaranteed business evidence. | Configuration, membership, entered discount, final override, risk/discount approval, agreement departure and confidential read are separate duty assessments. No role-name grants; permission vocabulary awaits R9 approval. Persist typed actor/time/reason/version/result evidence where required. |

Read-only findings are bounded to named docs/models/Action inventory, not an assertion about unseen
integrations. Shared checkout at review included committed CRM security `33a9806` and A `be70bda`,
and ongoing C/Item changes. These are implementation-owner work, not reverified or edited here.
No C2/CRM delivery document or current-state claim was changed by this research.

## Primary comparison and project synthesis

| Source, official page | Vendor observation | Convergence / divergence and project translation |
|---|---|---|
| [ERPNext Customer Group](https://docs.frappe.io/erpnext/customer-group) | Customer groups form a hierarchy; leaf assignment supplies defaults such as price list and supports reporting. | Useful segmentation, but shared classification/default hierarchy is not sufficient for overlapping independent eligibility. Proposed flat multiple pricing groups stay separate from non-exclusive business labels and financial account. |
| [Dynamics Commerce price management](https://learn.microsoft.com/en-us/dynamics365/commerce/price-management) | Price groups link rules to commercial entities; customers may have multiple affiliations. Priorities can override otherwise best-price behaviour; prices and discounts have separate priorities. | Multiple memberships are realistic. Do not copy default best-price, anonymous POS affiliation or Commerce channel infrastructure. One company-scoped audience eligibility model suffices, with intentional precedence. |
| [ERPNext Pricing Rule](https://docs.frappe.io/erpnext/pricing-rule) | Item/group/brand and party/group/territory applicability, stock-UoM quantity bounds, mixed/cumulative conditions, typed rate/discount/product effects. Priority and optional multiple-rule/discount-on-discount distinguish competition and compounding. | Applicability/effect/combination need separate facts. We choose declared tier measure, not universal stock basis; no Python scripts, arbitrary rule bypass, implicit mixed demand or historical cumulative qualification. |
| [ERPNext Item Price](https://docs.frappe.io/erpnext/item-price) | Price carries UoM, list, optional customer, minimum quantity and validity; packing-unit metadata is separately documented. | Confirms rate basis and eligibility matter. Our named package is already stronger identity: do not replace it with a generic packing-unit number or require CustomerAccount. |
| [Odoo 19 pricelists, official source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/products_prices/prices/pricing.rst) | Fixed/discount/formula rules target products/categories, quantity/date criteria and currencies. Discount and formula effects can have different customer presentation. | Reuse catalogue applicability and distinguish economic effect from display. No inference that category discounts always inherit or that selected package and stock basis are equivalent. Existing primary record supports P6, not our exact ranking. |
| [Dynamics within-component concurrency](https://learn.microsoft.com/en-us/dynamics365/supply-chain/unified-pricing-management/upm-concurrence-within-codes) | Exclusive, best-price, compounded, always-apply and attribute-rank modes; compound behaviour can use original or running price. | Selection and stacking are separate business policies, not a universal cheapest-first standard. We recommend one winner by rank and explicit ordered plans; no enterprise component-code framework needed. |
| [Dynamics sales agreements](https://learn.microsoft.com/en-us/dynamics365/supply-chain/sales-marketing/sales-agreements) | Agreement terms override ordinary trade terms; fixed price/discount departures affect linkage; quantity/value commitments and fulfilment are separate. | Preserve explicit contract intent; reject silent fallback/unlink. Quote selection does not prove commitment fulfilment/cancellation. Exact legal obligation is not inferred from software behaviour. |

Sources agree on audience/product/quantity/date eligibility, effect separation and explainable rule
configuration; they diverge on multiple membership, quantity aggregation, cheapest-price defaults,
stacking and override freedom. P1–P8 are our translation under existing authority, not vendor norms.
Only bounded observations above are drawn from each page; examples and recommended controls are
project proposals. No source establishes this ERP's exact price-floor/exception or downgrade policy.

## Proposed architecture seams before choosing tables

These are logical responsibilities to approve; physical names, migrations and APIs follow approval.
Avoid a universal polymorphic rule blob with unverifiable cross-company IDs or executable expressions.

| Responsibility | Required logical facts / boundary | Optional delivery later |
|---|---|---|
| Commercial segmentation | Party-based non-exclusive classification; distinct company pricing groups and dated memberships. Unique company codes, tenant-safe references, no mutually exclusive Party type. | Classification-management UX/explicit mapping automation. Membership history needed before group pricing activates. |
| Price catalogue and applicability | Version/list identity, company, currency/effective interval/status; effect family; audience Party/group/general/agreement; source Item/category/Assembly/group/manual policy; exact/subtree match, exclusions, declared basis/tier/context and priority. | Additional channel/location dimensions; typed extension only when justified. No extra catalogue tree. |
| Agreements | Selected identity/version, applicability, fixed versus referenced-discount terms, permitted extra adjustment plan, coverage and departure restrictions. | Commitment ledger/settlement/cancellation and separately approved departure workflow. |
| Adjustments and controls | Percent/per-priced-unit/per-line effects; named benefit identity/slot/plan/order; reference gross, limits, exclusions, floors, margin and advisory/mandatory/exceptionable flags. | Complex conditions, reward products and retrospective rebates. Automatic and discretionary benefit cannot apply twice under different labels. |
| Quantity context | Source/package/UoMs, offered/priced/stock values, factors, rate denominator, tier measure and LINE_LOCAL versus future selected-set aggregation. | Cross-line/category/historical cumulative eligibility; marginal slabs. New measures need explicit acceptance semantics. |
| Resolution evidence | Chosen/rejected versioned candidates, audience membership/category path, basis/interval, agreement intent, calculated/final net, adjustment/order, control result, actor/time/reasons and authorised decisions. | Full UI trace can be delivered progressively; server-sensitive projection is mandatory on every exposed surface. |
| Draft/issue integration | Relevant dependency fingerprints, adopted cost certainty, versioned controls/approval, immutable issued snapshots and scope/allocation references. | R5 lifecycle/locks/retries; R6 acceptance selection; R8 document rendering. No recalculation on read. |

Proposed invariant envelope: company-scoped unique identities and composite company references;
valid effective intervals; no contradictory active same-rank rule activation; acyclic category walks;
compatible rate/tier units; non-negative eligible monetary results and controlled percentage ranges;
append-only issued and decision evidence. Changes to membership/category/list/terms/control mark
relevant drafts stale, not every document indiscriminately. Currency/precision/rounding constraints
must be finalised with R4; money remains decimal. No DDL, migration or endpoint is authorised here.

Later approved specification must define each Action and Request guard, field projection, database
constraints, stable lock order, exact-intent idempotency, activation concurrency, refusal reason codes,
additive migration/rollback, grant rollout, UI states and regression criteria. Research does not
claim these implementation obligations are already delivered or fully selected.

## Boundaries and evidence sufficiency

Settle classification versus eligibility, category reuse, effect/basis, deterministic overlap,
combination, control/exception separation and snapshots now. Optional category/group/tier UI can
follow SIMPLE without replacing commercial lines. R4 owns date/FX/tax/rounding; R5 owns adoption,
draft/issue/approval; R6 owns partial/option acceptance; R7 handover/fulfilment; R8 presentation;
R9 scope/reporting/forecast. Later Finance owns credits/rebate settlement. No pricing engine, Sales
Order, returns, loyalty, free-goods or commitment engine is included in this planning authority.

Evidence is sufficient to distinguish these alternatives for owner review; finer currency/control
and selection semantics stay explicit later gates. Q43 is planning-originated. No implementation
question was supplied in this request, so none is invented or attributed to the coding agent.
Forwarded questions use the [existing-register reconciliation process](../architecture/implementation-question-reconciliation.md).

## Related

- [Revised decision matrix](phase-4-r3-business-review.md) · [Initial research](phase-4-r3-sales-pricing.md)
- [Pricing domain](../domain/sales-pricing.md) · [Phase 4 plan](phase-4-sales-quotations-and-handover.md)
- [Research method](../architecture/research-and-design.md) · [Open questions](../OPEN-QUESTIONS.md)
