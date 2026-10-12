[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R3 — Source-neutral Sales pricing: initial evidence and consolidated review

**Updated 2026-10-12 · CONSOLIDATED BUSINESS REVIEW READY / AWAITING OWNER APPROVAL.** Research started after the CRM
security handoff and policy review were documented. No R3 ADR is accepted, implementation slice
authorised, schema selected or application code changed. Planning owns this file; cost C2 recovery
and its delivery docs remain with the implementation agent. This is one owner-review matrix,
not a sequence of independently accepted pricing decisions. The [business-first P1–P6 review](phase-4-r3-business-review.md)
is the current owner-review agenda, including examples, realistic options and R4–R9 boundaries.

## Question, existing basis and boundaries

How can one pricing contract serve stocked Items, non-stock/service Items, Assembly-backed work
and controlled manual scope while simple quoting stays practical and governed pricing stays honest?
Reuse [sales-pricing](../domain/sales-pricing.md), accepted
[0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md) and its retained
[0055](../decisions/0055-progressive-sales-pricing-and-controlled-formulas.md) provisions. Do not
reopen Assembly-only selling or repeat R1/R2. Cost is an input when applicable, never the universal
price owner. Tax follows net price; Purchasing prices do not become Sales price rules.

Accepted foundations: SIMPLE/MANAGED/ADVANCED; maintained price, cost-plus markup, target margin;
deterministic specificity/priority/date/identity, not cheapest-wins; controlled typed formulas;
calculated versus final price and authorised override; immutable issue evidence. Assembly-specific
policy scopes remain for Assembly work only. New Item/manual applicability is this review's proposal.

## Primary-source comparison — accessed 2026-10-10

| Product / primary record | Observations | Implication, not copied policy |
|---|---|---|
| [Odoo 19 pricelists, official source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/sales/products_prices/prices/pricing.rst) | Optional activation; fixed price, discounts/formulas, product/category application, quantity and validity conditions, currency, ordered rounding/fee effects; prices can be overridden. Customer visibility differs by adjustment presentation. | Progressive exposure and fixed-versus-derived effects converge. Do not copy unrestricted override, automatic database-wide company applicability or negative-discount markup UX. Product categories do not establish our source hierarchy. Rendered page timed out; official source was read instead. |
| [ERPNext Pricing Rule](https://docs.frappe.io/erpnext/pricing-rule) | Applicability includes item/party/quantity/date/company/currency; rate can replace Item Price. Priority and optional multiple-rule compounding are explicit. Dynamic conditions can use Python. | Rule applicability, stacking and exception need distinct contracts. Reject arbitrary Python under retained 0055. Stock-UoM tier thresholds cannot be assumed correct for our separately offered/priced/estimated quantities. |
| [Dynamics sales agreements](https://learn.microsoft.com/en-us/dynamics365/supply-chain/sales-marketing/sales-agreements) | Quantity/value commitments over a validity period; agreement terms override trade agreements. Fixed-price/discount policy governs changing terms; confirmations retain versions; fulfilment measures are separate. | Binding agreements differ from a customer's preferred price list. Pricing agreements need provenance and controlled departure, not accidental lowest-price substitution. Cumulative commitment fulfilment is beyond initial quotation pricing. |

Convergence: explicit audience/basis/date/currency, maintained versus calculated effects, and
explainable adjustments. Divergence: precedence, override freedom, quantity basis, stacking and
commercial commitments. None defines complete cost for our mixed-source estimate; accepted
0110 supplies that boundary. Our recommendations below are project-specific synthesis.

## Practical source examples

| Scope | Example / proposed treatment |
|---|---|
| Stocked Item | Offer 2 cartons, 10 stock units each, PKR `1200.0000` per carton: net `2400.0000`, stock requirement 20. Price remains maintained when stock cost changes. A stock-unit cost must convert using the actual price basis before markup. |
| Service Item | Five hours at maintained PKR `3000.0000`/hour: net `15000.0000`. No inventory query. Cost-plus requires separately eligible service cost evidence; absent service cost is UNKNOWN, not free labour. |
| Assembly work | Complete cost PKR `100.0000`/sqm: 20% markup gives `120.0000`; 20% target margin gives `125.0000`. Use base cost, not already marked-up `sell_per_unit`. Quantity-dependent cost resolves the actual work scenario before deriving a comparable unit basis. |
| Manual scope | One survey at authorised PKR `5000.0000`: deliberate manual source with reason/evidence. No invented Item/Assembly/stock cost. Missing cost need not prohibit maintained/manual selling unless an applicable control requires it. |

## Consolidated owner-decision matrix

Each row distinguishes retained accepted rules from new recommendations. All new recommendations
are AWAITING APPROVAL; recommendations do not authorise implementation.

| Bundle | Recommendation / consequence | Realistic alternative / trade-off |
|---|---|---|
| P1 Source applicability | Shared resolver with source adapters. Item/service-specific policy → company; Assembly → Assembly Group → company; manual has company defaults and explicit line input, no fabricated source identity. Recipient prices use eligible Party identity without CustomerAccount. Retain effect/applicability separation. | Add Item-category hierarchy now: useful at scale but new conflict/maintenance semantics. Defer pending demonstrated need; Assembly groups remain applicable to their own sources. |
| P2 Precedence and agreements | Retain agreement → Party price → general maintained price → applicable source policy → authorised manual, else refusal. Explicitly choose an eligible agreement; never select among different commitments by cheapest rate. Within a source retain specificity/priority/effective date/stable identity. Detect overlapping configuration and explain tie-breaks. Locked agreement terms require their specific governed exception, never a generic price edit. | Best-price selection conflicts with retained 0055. Silent fallback from a selected invalid agreement hides commercial intent; recommend explicit repair/deselect rather than silent fallthrough. Full quantity/value agreement fulfilment is deferred to R6/R7. |
| P3 Discounts and exception | MANAGED supports typed percent/per-unit/line adjustments with ordered application and explicit stacking; default single-group plan with one applicable automatic discount; additional groups coexist only under explicit ordered stacking configuration. Preserve base, calculated net and final net. Manual price in SIMPLE is controlled entry when no calculated source exists, separately labelled from overriding a calculated result. Overrides require duty/reason and applicable approval. | Automatically stack all matches gives surprising totals; forbid initially. SIMPLE ordinary discount entry is an open packaging choice: recommend controlled final-price exception initially, preserving reason, rather than a hidden second discount engine. |
| P4 Complete cost and margin controls | Cost-plus/target-margin and any cost-consuming formula step require COSTED for the complete applicable quantity/basis. Maintained/manual may price with PARTIAL/UNKNOWN, visibly preserving uncertainty. Minimum-margin control evaluates final discounted/overridden net price; unevaluable configured control refuses issue pending a separately governed exception if approved, never assumes pass. Genuine zero is valid evidence; percentage margin at zero sell price is undefined. | Require cost for all prices blocks valid service/manual selling; extrapolate partial cost hides risk. Warning-only minimum margin may suit a policy, but must be explicitly configured and distinguished from mandatory control. No universal complete-cost mandate. |
| P5 Progressive enablement | SIMPLE first, with maintained/cost-derived/manual basics; MANAGED adds agreed lists/Party/tier/agreement/adjustment/control surfaces; ADVANCED adds typed versioned steps. Modes independent of CRM/tax. Activation validates prerequisites/permissions/rules. Downgrade preserves snapshots and refuses unresolved dependent drafts/configuration unless explicitly reconciled. | Enable every control globally creates unusable setup. Hiding active rules while silently retaining effects on downgrade is unacceptable. Exact downgrade draft handling needs an owner-reviewed transition before delivery. |
| P6 Evidence, reprice and confidentiality | Copy source/rule/quantity/UoM/FX/rounding/adjustment/override evidence on issue. Show stale draft inputs, explicit reprice and changed-price review; quantity/Party/date/basis changes revalidate. Maintained prices do not track cost. Internal cost/margin/candidate diagnostics remain capability-projected; customer receives approved commercial price/discount presentation only. | Auto-reprice without review surprises users; freeze all draft prices hides stale policy. R4 settles FX/tax/rounding dates; R5 settles adoption and approval fingerprints. Selective blind control evaluation may be allowed without disclosing protected operands. |

Further detail within these bundles: price-list currency must match document currency initially;
cross-currency price conversion needs R4's explicit pinned-rate contract, not today's implicit rate.
Tiers use a declared comparable selling/price quantity basis; no automatic summing of independent
options, repeated Item lines or alternatives. Draft repeated-line tier aggregation is deferred until
scope identity/eligibility rules are settled. Manual free-text descriptions cannot match permanent
price entries by text; a reusable service belongs in the Item catalogue by explicit choice.

## Proposed resolution and safeguards

Resolve effective company mode and eligible Party/source context → validate offered/price/stock/work
basis → select explicit agreement or applicable maintained source → otherwise source base method →
ordered applicable adjustments/formula → named rounding boundary → authorised final exception →
re-evaluate final-price controls → return projected explanation and issue-readiness diagnostics.
Tax follows the net line. A price rejection is distinct from unknown cost; no missing-price zero.

Retain high-precision decimal arithmetic until agreed unit/line/document rounding; money stored as
`decimal(19,4)` strings. Target margin denominator must be valid. No invented historic/future
Inventory valuation, provider fallback or reservation. A selected eligible zero price requires actual
price evidence and any free-supply policy review; zero quantity semantics remain R2/R6, not inferred.

Permissions: ordinary quotation maintenance, pricing configuration, final-price exception,
agreement departure, internal cost visibility and approval are separate duties where sensitivity
warrants. Exact new vocabulary/grants awaits approved slices; reuse existing capabilities where
suitable. Request and Action checks, company/record scope, source lookups and projected candidate
explanations must match. An eligible prospective Party is not permission to maintain Finance fields.

Advanced inputs retain defined type/UoM/permitted scopes; do not mechanically route every Item
through Assembly defaults. Retained allowlisted steps and intermediate evidence remain; versioned
preview examples must cover unavailable cost, invalid units, rounding, zero and rejected inputs.
No arbitrary scripts/SQL or script authoring UI.

## Delivery gates, API/UI coverage and candidate acceptance

No C2 dependency blocks research. Delivered/verified generic cost is required before trusted stocked
Item/Assembly cost-derived paths; services/manual need their own approved cost adapters. Maintained
pricing can be specified independently. R4 currency/tax/rounding and R5 lifecycle/snapshot adapters
block a complete issue-ready implementation, not this bounded proposal. R6 acceptance and R7
handover own commitment fulfilment; pricing never creates it.

After owner approval, each slice must include behaviour, lifecycle/invariants, permissions/grants,
scope, typed API/resources, generated consumers, complete enabled-mode screens, demos and observable
acceptance, with dependencies/exclusions. Screen coverage matrix must name list/overview/detail/
create/edit/preview/history/configuration and each filter/sort/include supplied by the actual API;
sheets and shared PO patterns by default. SIMPLE hides irrelevant advanced configuration while
preserving saved evidence. Phone, keyboard, light/dark, denied/empty/error/stale states are required.
No global retrofit or promise that all advanced modes ship in the first slice.

Candidate acceptance scenarios for later approved specifications:

- Equivalent commercial bases resolve correctly for all four source kinds without fake Assemblies.
- Maintained price stays fixed when cost changes; cost-derived draft becomes stale and requires
  explicit resolution. Issued evidence remains byte-for-byte historically meaningful.
- Complete cost `100.0000` produces `120.0000` markup versus `125.0000` margin; partial known
  `100.0000` produces neither complete cost-derived price. Explicit zero differs from unknown.
- Agreement dominates general cheaper price; invalid selected agreement refuses; overlapping rules
  resolve deterministically and show authorised explanation without leaking restricted candidates.
- Ordered 10% then 5% discounts on `100.0000` compound to `85.5000` only when stacking is enabled;
  disabled stacking never silently adds both. Override and discounts recheck final margin controls.
- Read-only/no-cost personas receive commercial results or opaque control refusal as authorised,
  never protected inputs, margin, recipe, stock detail or fingerprint-derived information.
- Unit/package/tier/currency/date changes invalidate relevant draft pricing/approval facts. Retry,
  stale-version and issue-versus-reprice races preserve one immutable issue snapshot.
- Upgrade/downgrade cannot bypass active obligations; historical issued snapshots remain readable.

## Current state and next owner review

ACCEPTED: 0109 and retained 0055 boundaries. IMPLEMENTED: Estimating guidance and internal C1
cost query, not Sales pricing. C2 completion is reported locally in STATUS and implementation commits;
this planning session does not independently reverify it. DEFERRED:
pricing delivery, full agreement fulfilment, R4–R9 gates. AWAITING APPROVAL: P1–P6 recommendations,
including SIMPLE discount packaging, agreement exception duties and downgrade draft handling.
CRM A/B/C and Q42 are accepted in 0111–0114; security correction uses existing authority.

## Next consolidated review — P1–P6 remain unaccepted

CRM A/B/C and correction authority are accepted in 0111–0114, separately from pricing.
No CRM decision changes price precedence, selling source or cost completeness. Research reuses
our initial primary comparison and 0109's retained rules; questions below sharpen its proposal,
not new ADRs or accepted controls. Cost recovery's delivery record remains implementation-owned.

| Bundle / next review question | Recommended default / practical consequence |
|---|---|
| P1: source-specific policies and recipient identity | Item/service → company; Assembly → Group → company; manual explicit line input; Party-specific prices without fabricated CustomerAccount. Defer Item-category hierarchy and text-matched manual price records. |
| P2: selection versus binding agreement | Explicit eligible agreement first; invalid selected agreement blocks until repaired/deselected under authority, rather than silently reverting to a cheaper list. Treat negotiated pricing terms separately from cumulative quantity/value commitment fulfilment. |
| P3: SIMPLE discounts and stacking | SIMPLE controlled final-price exception initially; MANAGED explicit ordered discounts with stacking disabled unless configured. Decide whether routine SIMPLE line discount deserves first-class exposure; it must still preserve calculated/final price and margin-control evaluation. |
| P4: incomplete cost and control exceptions | Maintained/manual selling may proceed with clearly unknown margin when no mandatory cost control applies. Cost-derived methods require complete applicable cost. Decide whether a configured unevaluable mandatory margin control has a separately governed exception; no silent warning/pass. |
| P5: activation/downgrade with existing drafts | Preserve issued evidence and reconcile dependent drafts/rules before downgrade. Recommend refusing downgrade until affected drafts are repriced, resolved or explicitly withdrawn; hiding a formula control cannot leave its unexplained effects active. |
| P6: review and confidentiality | Explicit reprice for stale drafts; separate protected operands/intermediates from commercial result. Decide customer discount presentation and which internal explanations ordinary pricing users may see; calculation authority does not grant cost/recipe evidence access. |

Boundary examples for discussion: a maintained service rate can remain valid without an inventory
cost; a mandatory margin floor may still make its issue unevaluable. A zero-cost stocked Item with
valid evidence can use markup arithmetic, but zero selling price makes percentage margin undefined.
A selected agreement fixes commercial intent; deselecting it is not proof a purchase commitment was
cancelled. A 250-sqm Assembly demand must use the actual quantity-dependent cost basis, not a cheap
one-unit FIFO query multiplied into a job. These apply accepted distinctions; packaging/exception
choices remain owner-review items.

Resolve P1–P6 together before a new pricing ADR. Exact FX/rounding/date policy stays R4;
adoption/issue and relevant-approval fingerprints stay R5; acceptance/group/tier aggregation stays
R6/R9. No universal trusted forecast, job creation or Inventory posting follows price resolution.

## Related

- [Current consolidated business review](phase-4-r3-business-review.md)

- [CRM implementation specification](phase-4-crm-correction-specification.md) · [CRM policy review](phase-4-crm-policy-review.md) · [Security handoff](phase-4-crm-security-correction.md)
- [Pricing domain](../domain/sales-pricing.md) · [Accepted 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- [Research standards](../architecture/research-and-design.md) · [Capability delivery](../architecture/capability-delivery-contract.md)
- [Screen delivery](../architecture/screen-delivery-contract.md) · [C0](phase-4-c0-cost-evidence-contract.md)
