[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R3 — Consolidated business review of P1–P6

**2026-10-12 · AWAITING OWNER REVIEW.** No P1–P6 recommendation below is accepted and no pricing
ADR/code is authorised. Read this as one coherent proposal. [Initial research](phase-4-r3-sales-pricing.md)
retains source comparison/technical boundaries; this record explains the business decisions.
Accepted ADR 0109 generalises selling beyond Assemblies while retaining the valid provisions of 0055.
CRM A/B/C and Q42 authority do not decide pricing policy.

## What already exists and what this review decides

Implemented: Items/services, Parties, Estimating cost/guidance and Inventory/Estimation cost resolution.
STATUS and commits `1d77fc0`, `0ba5970`, `72106aa` report local C2 completion; this planning session
has not rerun its checks. Sales quotation/pricing/acceptance implementation is pending.
Accepted: optional estimates and four commercial scope sources; maintained/cost-plus/target-margin
base effects; progressive modes; explainable precedence, no cheapest-wins; typed restricted formulas;
price/cost/tax separation, controlled overrides and immutable issued evidence.
New decisions: source-specific applicability, agreement selection/exceptions, adjustment stacking,
incomplete-cost control behaviour, operational mode transitions and draft review/presentation.

## P1 — Price the thing actually being sold

**Already accepted/existing:** direct stocked Items, service/non-stock Items, Assembly-backed work
and controlled manual scope share one quotation model. Assembly Groups remain relevant only to
Assembly work. Offered, priced, stock and estimating measures stay distinct. Cost/guidance exists;
a maintained Sales price catalogue does not yet exist.

**New decision:** where defaults and more specific policies belong for Items/services/manual scope,
and whether Item categories gain pricing inheritance now. Recipient prices must identify an
eligible contracting Party; prospect quotation does not imply financial account creation.

**Example:** a decorator sells paint by carton, installation by sqm, and a five-hour consultation.
Each needs a customer price in its offered basis. A manual survey line should not require a fake
Assembly. Two cartons of ten units priced PKR `1200.0000` each mean net `2400.0000` and stock
requirement 20; carton price is not a recipe conversion.

**Options/trade-offs:** company defaults only are easy but too coarse for differing products;
source-specific overrides preserve practical control; adding category inheritance now helps bulk
maintenance but introduces another hierarchy and overlap rules before its need is established.

**Recommendation:** Item/service-specific policy → company; Assembly → Assembly Group → company;
manual scope uses applicable company defaults and explicit line inputs, with no description-text
matching to permanent prices. Party-specific maintained pricing works without artificial accounts.
Use one resolver and suitable source adapters. This gives practical variation without imposing
Assembly structure or premature Item-category pricing on every company.

**Deferred:** R4 currency/FX and precise commercial rounding; R5 adopted estimate/snapshot adapters;
R6/R9 scope/tier aggregation across repeated or optional lines. Customer-group pricing waits for a
real Party segmentation model; service/manual cost adapters need explicit legitimate evidence.

## P2 — Honour an agreement without confusing it with a price preference

**Already accepted:** retained precedence is agreement → Party-specific maintained price → general
maintained price → applicable source policy → authorised manual price, otherwise refuse. More
specific eligible records, priority/date/stable identity resolve deterministically; cheapest-wins
is rejected. Agreement creation/selection/lifecycle is not implemented.

**New decision:** explicit agreement selection, what invalid/conflicting agreements do, and whether
ordinary price override may depart from fixed terms. Distinguish an agreed price schedule from a
quantity/value purchase commitment: selecting pricing terms does not create accepted business.

**Example:** maintenance agreement price PKR `900.0000`/hour, customer's ordinary rate `1000.0000`,
public promotion `850.0000`. An offer under the agreement uses its eligible terms; it must not quietly
switch to `850.0000`. If the selected agreement expires, that is a visible decision to resolve.

**Options/trade-offs:** cheapest-wins can breach intended terms and conflicts with accepted direction;
automatic agreement matching is convenient but ambiguous where multiple commitments overlap;
explicit selection makes commercial intent visible, with automatic discovery of eligible candidates.
Silent fallback on expiry avoids interruption but loses the selected basis; explicit repair/authorised
change requires an extra decision and preserves evidence.

**Recommendation:** explicit eligible agreement selection; surface alternatives without automatically
choosing between different commitments. Once selected, invalid terms block resolution until repaired
or deliberately changed under applicable authority. Ordinary final-price override does not break a
fixed agreement. Initially fixed terms cannot be departed from inside an ordinary quotation edit;
a later governed agreement-departure path can be designed if genuinely needed. Preserve agreement
identity/version, applicability and agreed adjustment permissions; extra discounts require terms
that allow them. Deselecting pricing context is never proof that an underlying obligation was cancelled.

**Deferred:** R4 exact date/currency eligibility; R5 agreement-change approval/issue evidence;
R6/R7 commitment fulfilment, cancellation and handover; R9 sensitive agreement lookup/reporting.
No legal contract validity, subscription or quantity/value fulfilment engine is implied by this review.

## P3 — Make negotiated reductions and combined discounts explainable

**Already accepted:** adjustments differ from base-price effects; calculated and final price must
both survive an override, with authority/reason and applicable approval. MANAGED adds discount/
surcharge rules; SIMPLE supports controlled manual pricing. Precise stacking and SIMPLE packaging
remain open. Neither adjustment nor override grants cost-read or agreement-departure authority.

**New decision:** stacking default/order, ordinary discretionary reductions in SIMPLE and the
relationship between calculated discounts and a manual final-price exception.

**Example:** PKR `1000.0000` has a 10% customer discount and a 5% campaign discount. Choosing one
may produce `900.0000`; adding percentages produces `850.0000`; sequential application produces
`855.0000`. These are different offers, not presentation variations. A negotiated `950.0000` is
another explicit final-price exception, not a hidden edit to the maintained `1000.0000` rate.

**Options/trade-offs:** strongest-discount selection is predictable only if chosen deliberately,
not as cheapest-base-price policy; stack-everything surprises sellers; explicit ordered stacking
is flexible but needs configuration. SIMPLE first-class discount entry is familiar but introduces
adjustment UX; a controlled final-price exception is smaller and fits retained SIMPLE authority.

**Recommendation:** default no automatic stacking; choose one eligible automatic adjustment per
explicit adjustment group using deterministic rules. The default discount plan has one group;
multiple groups do not combine unless explicitly configured to coexist in a named ordered plan.
MANAGED may enable named, ordered compounding within that plan. Keep percent, per-unit and line adjustments typed and distinguish their
bases. SIMPLE initially negotiates through controlled final-price exception with base/final/reason
visible; routine automatic discount/tier configuration remains MANAGED. If first-class SIMPLE
percentage/amount discount entry is desired, approve it explicitly as bounded discretionary exposure.
A manually entered final net replaces the calculated net, does not receive the same discount twice,
and rechecks agreement constraints and final-price controls.

**Deferred:** R4 tax/rounding allocation; R5 approval/fingerprint and issue evidence; R6 option/partial
acceptance effects on document adjustments; R8 discount presentation; R9 duty/scope vocabulary.
Document-wide discount allocation, promotions/free-product engines and cumulative tiers stay separate.

## P4 — Separate price availability from known margin and risk exceptions

**Already accepted:** missing cost is not zero; COSTED/PARTIAL/UNKNOWN are distinct. A maintained
price need not move with cost; markup is not margin. Cost-aware policy evaluation can be authorised
without disclosing protected amounts. Accepted pricing capability includes minimum-margin controls
in MANAGED, but their policy/refusal/exception semantics are not yet settled.

**New decision:** which calculations require complete cost and how a mandatory margin check behaves
when cost is incomplete or margin too low. A commercial override and a risk exception are separate
facts; neither can manufacture complete cost or satisfy a missing input.

**Example:** a survey has a maintained PKR `5000.0000` price but no supported service-cost estimate.
Selling can be valid while margin is unknown. If a company requires proof of minimum 20% margin,
that requirement is unevaluable. Separately, known cost `800.0000` and net price `1000.0000` gives
20% margin; a discount to `900.0000` reduces it below the floor even though the original price passed.

**Options/trade-offs:** require complete cost for every offer blocks valid maintained/manual sales;
allow every unevaluable check to pass hides uncertainty; configurable warning versus mandatory
control expresses different business risk. A governed exception permits deliberate commercial risk
but must remain visible, authorised and tied to the actual uncertain/below-floor facts.

**Recommendation:** complete applicable cost for cost-plus, target-margin and every cost-consuming
formula/control. Maintained/manual pricing may proceed without it when no mandatory cost control
applies. Evaluate controls on final net price after discounts and exceptions, with named compatible
basis. Policies may be advisory or mandatory; mandatory failures/unevaluable results block issue.
Initial delivery has no blanket risk bypass. If exceptions are approved, model a separate, reasoned,
evidence-bound authority/approval path that permits risk while leaving margin unknown or below floor.
Do not use ordinary price-edit authority as that path. Zero cost with evidence is valid; percentage
margin at zero selling price is undefined. A known subtotal is never used as the complete denominator.

**Deferred:** R4 currency/rounding needed for comparable margin; R5 issue/approval invalidation;
R6 accepted-scope changes; R7 estimate/actual cost handover; R9 confidential controls and audit.
No new Inventory valuation, price-to-cost inference permission or universal approval is implied.

## P5 — Grow pricing capability without changing what old offers meant

**Already accepted:** SIMPLE is initial; MANAGED adds effective-dated lists/Party/tier/agreement/
adjustment/margin control; ADVANCED adds typed, versioned ordered formulas. Modes are independent
of CRM/tax and selected by business need, not company headcount. Arbitrary scripts/SQL are excluded.

**New decision:** safe activation, downgrade handling and what each enabled mode exposes across the
workflow. A display preference cannot turn off authoritative rules or approvals.

**Example:** a small specialist starts with maintained rates, adds contract/tier pricing, then needs
`(complete cost + per-unit charge) × factor`. After issuing that offer, disabling ADVANCED must not
remove its explanation or leave its formula silently active on an apparently SIMPLE draft.

**Options/trade-offs:** show everything from day one burdens routine quoting; feature flags per
control permit unexplained combinations; automatic conversion on downgrade may erase negotiated
intent. Blocking until affected configuration/drafts are reconciled is explicit but needs a useful
impact preview and practical remediation.

**Recommendation:** coherent independent pricing modes, SIMPLE first. Activation validates sources,
configuration, authority and all affected forms/detail/preview/history. Before downgrade, show impact
and require affected drafts to be repriced/resolved or explicitly withdrawn under their own rules;
then archive/deactivate incompatible future configuration. Do not invent withdrawn-draft lifecycle
here. Historical snapshots remain readable and unchanged. Advanced inputs/steps are allowlisted,
typed and versioned with preview examples; company-specific logic remains explainable without scripts.
Delivery can progress in approved slices without pretending undelivered modes are enabled.

**Deferred:** R5 exact draft/change/withdrawal and stale-approval transitions; R8 historical document
presentation; R9 configuration duties/scope/audit. R4 establishes currency/rounding inputs. No pricing
mode creates a selling team, financial account, forecast publication or job.

## P6 — Review a changed offer and show the right explanation to each reader

**Already accepted:** immutable issued price/evidence; explicit staleness handling; maintained price
independent of cost; price exception retains calculated/final values. Customer projection omits
internal estimate/cost details. Viewing a document must not silently change its terms.

**New decision:** draft recalculation/reprice review, no-change confirmation when relevant inputs
change, and customer-visible discount versus internal price-building explanations.

**Example:** draft installation cost moves from `100.0000` to `110.0000`/sqm. A 20% cost markup
would move the price from `120.0000` to `132.0000`; a maintained `120.0000` rate stays fixed but
its margin check may need review. The customer receives commercial scope/net/tax, not labour rates
or the internal formula operands. Issued earlier offers retain their own captured evidence.

**Options/trade-offs:** automatic overwrite removes seller control; freeze all drafts ignores new
eligibility/control facts; explicit changed-result review costs a step and preserves intent.
Always showing discounts can expose commercial negotiation strategy; always hiding them prevents
agreed transparent discount presentation. Internal trace detail needs field authority, not mere access
to the final price.

**Recommendation:** flag affected drafts, show an authorised before/after commercial comparison,
and require explicit reprice/confirmation of current relevant facts before issue. A retained manual
final price still revalidates dependencies and controls; it never suppresses stale evidence. Keep
calculated/final values and source/version/quantity/UoM/FX/rounding/exception evidence. Customer
projection may show explicitly customer-facing commercial discounts; internal formula adjustments
remain internal, with presentation intent captured. Cost/margin/candidate/recipe/stock evidence is
server-projected by duty; authorised internal evaluation never gives unrestricted operand access.

**Deferred:** R4 tax/FX/rate dates and rounding; R5 adoption/issue concurrency and approval fingerprints;
R6 revised/accepted commercial effects; R8 print/delivery/templates; R9 read scopes/explanation/report
basis. Issuing does not automatically publish forecast, accept work or create a job.

## Consolidated decisions requested

Review P1–P6 together: source policies; explicit agreement intent/fixed-term departure; stacking and
SIMPLE discretionary reductions; cost-completeness/advisory-versus-mandatory controls/risk exception;
mode activation/downgrade; explicit draft review/customer disclosure. The recommendations fit one
shared resolution sequence. Exact capabilities, schema, API and delivery slices follow approval;
no implementation or new R3 ADR follows from documenting this review.

## Research basis — verified 2026-10-12

Reuse accepted 0109/retained 0055 and the [2026-10-10 comparison](phase-4-r3-sales-pricing.md#primary-source-comparison--accessed-2026-10-10).
Targeted official-source refresh supports the unsettled agreement/stacking/presentation distinctions:

- [Dynamics sales agreements](https://learn.microsoft.com/en-us/dynamics365/supply-chain/sales-marketing/sales-agreements):
  agreement prices override trade terms; fixed price/discount departures affect the agreement link;
  quantity/value fulfilment is separate. We retain visible intent, not automatic unlinking/cancellation.
- [ERPNext Pricing Rule](https://docs.frappe.io/erpnext/pricing-rule): explicit priority, optional multiple
  rules and compounding distinguish selection from stacking. We do not copy Python conditions,
  stock-UoM tier assumptions or unrestricted ignore-rule behaviour.
- [Odoo 19 official pricelist source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/products_prices/prices/pricing.rst):
  optional activation, fixed/discount/formula effects and different customer discount presentation.
  We preserve explicit authority and history rather than copying open overrides or default-list ordering.

Convergence is applicability, effect and explanation; priorities, commitments and override freedom
differ. Complete mixed-source cost, approved risk exceptions and safe downgrade are project decisions,
not universal vendor behaviour. Evidence now supports the consolidated review; no tax/legal policy
or new market-standard claim is inferred. Research readiness is not owner acceptance.

## Related

- [Initial R3 record](phase-4-r3-sales-pricing.md) · [Pricing domain](../domain/sales-pricing.md)
- [0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- [Capability delivery](../architecture/capability-delivery-contract.md) · [Screen delivery](../architecture/screen-delivery-contract.md)
- [CRM handoff](phase-4-crm-correction-specification.md) · [Phase 4](phase-4-sales-quotations-and-handover.md)
