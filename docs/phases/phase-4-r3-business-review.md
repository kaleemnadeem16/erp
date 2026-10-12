[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R3 — Revised consolidated pricing decision matrix

**2026-10-12 · PROPOSED / OWNER APPROVAL REQUIRED · Q43 (origin: PLANNING RESEARCH).**
This replaces the earlier P1–P6 review agenda, not accepted authority. No new pricing ADR,
implementation permission or physical schema is accepted. Read the bundles together; the
[expanded evidence and repository reconciliation](phase-4-r3-expanded-research.md) distinguish
vendor facts from project recommendations. CRM ADRs 0111–0114 remain independently accepted.

## Accepted and implemented baseline

Implemented masters: company-scoped Party identity and optional CustomerAccount, Items including
services, one Item category with an existing parent tree, item-specific alternate units and named
fixed-content packages. Estimating owns Assembly/Assembly Group and cost guidance; these are not
Sales price lists. Local cost-contract delivery is reported in STATUS under 0110; planning has not
rerun its checks. No delivered quotation/pricing resolver, pricing-group membership or discount
matrix was found in the reviewed Sales model/Action inventory. CRM delivery is separately owned.

Accepted [0103](../decisions/0103-issue-quotations-to-party-backed-prospects.md),
[0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md), retained
[0055](../decisions/0055-progressive-sales-pricing-and-controlled-formulas.md),
[0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md),
[0080](../decisions/0080-packaged-purchasing-and-independent-price-basis.md) and
[0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md) govern:
prospect quotations without accounts; optional estimates; offered/priced/stock quantities distinct;
maintained/cost-plus/target-margin effects; deterministic explanation, never cheapest-wins;
typed formulas; cost/price/tax separation; missing cost not zero; immutable issued evidence.

**Changes needing explicit approval:** customer-group/category scopes extend retained precedence;
first-class SIMPLE discounts extend 0055's listed mode exposure; new overlap refusal below refines
its stable-identity tie-break (identity remains an ordering aid, not authority to resolve materially
different prices). Any accepted refinement needs a new ADR with explicit retained/superseded scope.
No contradiction is silently implemented. P1–P6 retain their IDs; P7 adds quantity semantics and
P8 adds optional selling context because each needs a distinct owner decision.

## Decision matrix — what must be settled, and when

“Now” means settle the architectural contract before pricing implementation, not implement today.
Each row names unresolved semantics; recommendations are unaccepted. Amounts are PKR and
`decimal(19,4)` examples; percentages are explicit, not floating-point implementation guidance.

### P1 — Customer eligibility and catalogue applicability

| ID / accepted basis | Unresolved decision and example | Options and trade-offs | Recommendation / why | Support now; later dependency |
|---|---|---|---|---|
| P1a · Party/account separation, 0103/0105 | Distributor A is also a contractor and preferred partner; a prospect has no debtor account. | One exclusive customer type is simple but loses overlapping roles; one hierarchy mixes reporting and price eligibility; separate concepts add maintenance. | Company-defined, non-exclusive commercial classifications on Party, separate from financial role and flat pricing groups. Classification alone applies no price; an explicit governed mapping may propose membership later. No hardcoded industry types or financial account prerequisite. | Party-based eligibility and separate concepts now; classification UI/automated mappings optional later; R9 visibility/duties. |
| P1b · Group pricing deferred in 0055 pending real segmentation | Distributor and preferred-partner groups both match. | Single membership limits legitimate cases; best discount changes intent; multiple membership with configured precedence requires conflict validation. | Multiple effective-dated Party pricing-group memberships; group eligibility nominates candidates, never stacks them. Rule priority resolves equal group scopes; unresolved material overlap refuses. Flat groups first, no customer hierarchy. | Membership/version evidence now for MANAGED foundation; group UI later slice; R5 fingerprints, R9 membership/configuration authority. |
| P1c · Existing Item category tree; Item/manual scopes await R3 | Oil-based parent gives 20%; child industrial gives 22%; A has 25% category and 35% exact product. | Duplicate pricing hierarchy drifts; parent-only rules miss children; implicit inheritance affects products unintentionally. | Reuse Inventory categories; configured rule chooses EXACT_CATEGORY or SUBTREE. Exact Item > nearest matching category > ancestors > company within an audience tier. Party > group > general is the audience axis. Assembly > Assembly Group > company remains separate. | Typed source/audience applicability now; category matrix UI MANAGED later; R5 category-change staleness; R9 configuration scope. |
| P1d · Classification is not authority | Premium product must not receive general category discount; new Item joins oil-based category. | Implicit all-category discounts surprise; global exclusion may suppress an intentional agreement; rule-scoped exclusions need clear explanation. | Explicit Item/category exclusions attached to a named discount family/plan; deny wins within that family, including against a positive descendant match. No exemption inferred from a more specific price. Separate authorised agreement terms are evaluated independently; mandatory global controls still apply. New Item automatically matches an active SUBTREE rule unless excluded, never by category alone. | Exclusion domain and inclusion preview now; exact UI later; R5 category moves/new membership invalidate dependent drafts; R9 authority. |

Example: general price `1000.0000`; distributor category adjustment 20% gives `800.0000`.
A's Party/category 25% replaces that adjustment with `750.0000`; A's Party/Item 35% gives
`650.0000`. These are three candidates for one adjustment slot, not cumulative reductions.
Water-based rules are separate configured applicability. Premium exclusion removes the general
category family; a selected agreement may provide its own permitted price, not bypass a global floor.

A category-wide *policy* (e.g. cost markup) or percentage adjustment is usually meaningful across
products. A uniform category *fixed price* is allowed only with an explicitly compatible pricing
basis and intentional configuration; never assume a drum and a brush share one monetary rate.
No existing Item Group master distinct from categories was found; do not add a synonym hierarchy.

### P2 — One selected base and explicit contractual intent

| ID / accepted basis | Unresolved decision and example | Options and trade-offs | Recommendation / why | Support now; later dependency |
|---|---|---|---|---|
| P2a · Agreement → Party → general → source policy → permitted manual, 0055/0109 | Party/category rate competes with group/Item and general/Item rate. | Product-first can override personal terms; audience-first preserves deliberate customer exceptions; arbitrary priority across all levels erases hierarchy. | Insert group between Party and general; audience-first, then product specificity, then explicit priority, latest effective start and stable ordering. Currency, unit, date, tier and context are eligibility gates, not price comparisons. | Source order now; R4 dates/currency; R5 pin/issue; R9 lookup protection. |
| P2b · Agreement facts distinct from ordinary preferred prices | Agreement fixes `850.0000`/carton; promotion offers 15% off `1000.0000`; two contracts eligible. | Automatic cheapest or latest agreement can select wrong commitment; explicit selection costs a step. | Discover eligible agreements but select explicitly at document/line applicability; one applicable agreement per line initially. Selected terms govern its base/adjustment allowance. Invalid selection blocks repair or deliberate reselection, no silent fallback. No ordinary override departure. | Agreement identity/version/intent now; R4 eligibility date; R5 change/approval; R6/R7 fulfilment, no cancellation inference. |
| P2c · Fixed price differs from discount effect | `850.0000` fixed and 15% off maintained `1000.0000` coincide today; list becomes `1100.0000`. | Flatten to net loses behaviour; calculate both as discounts changes fixed terms. | Fixed rate stays `850.0000`; 15% yields `935.0000` after explicit reprice. Keep reference list/version for discount, not a fabricated list discount for fixed rate. Campaign rule declares BASE_REPLACEMENT or ADJUSTMENT, never both for the same benefit. | Typed effects/dependency graph now; R4 precision; R5 staleness; R8 commercial presentation. |
| P2d · Stable deterministic selection, no cheapest-wins | Equally ranked group rules give 20% and 25%; two future versions overlap. | ID tie-break is predictable but commercially arbitrary; refusal needs administrator repair; explicit priority is intentional. | Reject known conflicting activation; at runtime refuse materially different equal-rank overlaps. Stable ID orders explanations or equivalent effects only. Intentional superseding dated versions identify predecessor; reject accidental overlap. | Conflict semantics now; requires explicit 0055 refinement if approved; R5 configuration concurrency; R9 repair duty. |

Proposed resolution sequence:

1. Authorise company/document/read context; validate source, Party, quantity/conversion, currency,
   pricing date and explicitly enabled context. No valid eligible source means refusal, never zero.
2. If an agreement is selected and covers the line, resolve its exact terms first. Any uncovered
   line follows ordinary sources only where agreement coverage rules explicitly permit that.
3. Otherwise select base: Party maintained → group maintained → general maintained → source policy
   → authorised entered manual base. Inside each audience: Item → nearest eligible category →
   parent categories → company. Assembly scopes use Assembly → Assembly Group → company instead;
   manual text has no invented catalogue matching. Item-package exactness is a declared basis gate.
4. Select adjustments under P3; exclusions and agreement allowance filter before selection.
   Evaluate final controls under P4, then R4 rounding/tax under its future accepted contract.
5. Record accepted/rejected candidate IDs, ranks, basis, memberships, exclusions, agreement versions
   and calculation evidence. Protect sensitive candidate terms as well as cost operands.

A general exact-Item base does **not** replace a Party category base under audience-first ordering.
A category cost policy does not outrank an eligible maintained price. A campaign fixed base enters
its declared Party/group/general tier and still obeys that ranking; it has no universal promotion
precedence. Agreement and ordinary customer discounts never combine merely because both match.

### P3 — Discounts, promotions and everyday SIMPLE selling

| ID / accepted basis | Unresolved decision and example | Options and trade-offs | Recommendation / why | Support now; later dependency |
|---|---|---|---|---|
| P3a · Controlled manual/override; routine SIMPLE discount not yet accepted | Salesperson reduces `1000.0000` by 10% for an ordinary sale. | Every reduction as exceptional override burdens normal work; unrestricted discount bypasses control; bounded discretionary adjustment is familiar and auditable. | SIMPLE exposes one optional routine discount per line: percentage OR fixed per-priced-unit OR fixed line amount, explicitly labelled. Ordinary manual base entry, discretionary discount and exceptional final-net override remain separate facts. Company-set authority limits; no reason mandated for in-limit routine discount, actor/time always captured. | Approve SIMPLE exposure now as 0055 refinement; UI may be an approved slice later; R5 approvals, R8 display, R9 exact duties. |
| P3b · Adjustment distinct from base | Three cartons at `1000.0000`: 10%, `100.0000`/carton, or `100.0000`/line. | Generic amount is ambiguous; typed bases add a small selector. | Net respectively `2700.0000`, `2700.0000`, `2900.0000`. Fixed per-unit uses declared pricing denominator; fixed line applies once. Reject negative net, invalid percentages or discount greater than eligible amount; no silent clipping. Free-goods are not monetary discounts. | Types/quantity basis now; R4 allocation/rounding, R6 partial acceptance. |
| P3c · No precise stacking accepted | 10% group and 5% season rule overlap. | Cheapest discount or additive percentages are policies, not inevitabilities; no-stack is easy; ordered plans require configuration. | Default one automatic winner in one discount slot using P2 ranking; no largest-discount rule. MANAGED may configure named ordered coexistence of slots with running-net compounding. Complex conditional stacking ADVANCED. 10% then 5% gives `855.0000`, not `850.0000`. | Plan identity/order/combination gate now; configured stacking later; R5 snapshot/fingerprint, R9 management. |
| P3d · Preserve calculated/final and agreement terms | Customer already gets 20%, seller enters another 10%; two campaigns active. | Automatically add risks double benefit; substitute loses intended negotiated addition; explicit allowed coexistence is clear. | Routine discretionary adjustment occupies the default slot and replaces the automatic candidate only with explicit seller confirmation; proposed default is no extra stacking. A plan/agreement may expressly allow a discretionary slot after automatic discounts. Campaigns compete unless named plan allows both. Final-net override replaces computed net and applies no further discount afterwards. | Combination semantics now; R5 authority/approval; R8 customer discount presentation. |
| P3e · Entry ≠ approval authority | Seller limit 10%, asks for 15%, while automatic terms already reduce 20%. | Role name alone is unsafe; checking each input alone permits split discounts; total effective reduction captures risk but needs reference evidence. | Separate enter/override/approve duties and effective limits by company role/employee assignment; record source of limit. Check discretionary limit and aggregate reduction against an explicit reference gross, plus mandatory floors. Above limit routes to independent approval if configured, otherwise refuses. Contractual allowances still bind. | Limit bases/separation now; no permission names accepted yet; R5 approval engine/fingerprint; R9 grant rollout. |

Ordinary discount is a recorded customer-facing commercial adjustment. Automatic discount is a
configured benefit. Manual final-net override is a reasoned exception preserving calculated net.
Contract departure is a separate, initially unavailable operation requiring future explicit approval;
manager discount approval does not grant it. A policy must say whether fixed preferred prices allow
extra discounts; recommend default no extras on contract terms, ordinary maintained bases governed
by the named plan. Zero reference gross makes percentage authority undefined, not zero discount.
Authority reference is the governed selected gross/version, not a seller-inflated manual number;
changing the base cannot reset an aggregate limit. Where no trusted reference exists, percentage
limits are unevaluable and require an explicit absolute-limit/approval policy, never silent passage.

### P4 — Final-net margin, floor and commercial risk

| ID / accepted basis | Unresolved decision and example | Options and trade-offs | Recommendation / why | Support now; later dependency |
|---|---|---|---|---|
| P4a · 0110 complete/partial/unknown, no invented zero | Maintained service `5000.0000`, cost unknown. | Complete cost for every sale blocks valid services; passing unknown cost hides risk. | Cost-derived methods/operands require complete applicable cost. Maintained/manual may proceed if no mandatory cost control applies; unknown remains explicit. Complete genuine zero is valid; zero selling price has undefined percentage margin. | Certainty/evaluation contract now; R5 adoption, R7 estimate/actual handover. |
| P4b · Minimum-margin capability retained | Known cost `800.0000`, price `1000.0000`, discount to `900.0000`; floor `950.0000`. | Warn only permits accidental loss; mandatory can block legitimate deals; advisory/mandatory per control makes intent visible. | Evaluate final net after all reductions in comparable quantity/currency basis. Margin at `900.0000` is about 11.11%, below 20%; independent price floor also fails. Minimum-price floor can work without cost when its basis is known. Mandatory unknown/below control blocks issue; no auto-clamp that rewrites terms. | Separate typed controls now; R4 comparability/rounding recheck, R5 issue, R6 accepted scope. |
| P4c · Price edit grants no risk bypass | Manager approves below floor or accepts unknown margin. | Ban exceptions is strongest; blanket manager bypass is opaque; scoped evidence-bound exception is auditable. | Support a separately authorised, reasoned risk request/decision contract now, optional delivery later. Initial mandatory controls have no bypass until that path is separately approved. Policy names exceptionable controls; unknown-cost risk approval never supplies cost or enables cost-derived arithmetic. No exception to tenant/contract restrictions. | Evidence/control IDs/revision/net/cost fingerprint now; R5 approval validity/concurrency; R9 sensitive duty owner approval. |

Advisory failures warn visibly; advisory unknown reports “cannot evaluate”. No-cost-read sellers may
receive authorised pass/warn/refuse results under 0110 without cost amounts, percentages or inferable
threshold traces. Advisory/mandatory, discount maximum, unit/line floor and margin are independent;
all applicable mandatory controls must pass or have their own valid permitted risk evidence.

### P5 — Progressive capability and safe transition

| ID / accepted basis | Unresolved decision and example | Options and trade-offs | Recommendation / why | Support now; later dependency |
|---|---|---|---|---|
| P5a · SIMPLE → MANAGED → ADVANCED, independent of CRM/tax | Small shop enters normal unit price and 10%; distributor needs category matrix. | Fixed-price-only SIMPLE under-serves; enterprise setup burdens ordinary quoting. | SIMPLE: maintained prices, ordinary manual bases, cost-plus/target-margin where cost supports them, bounded discretionary discounts and controlled exceptions. MANAGED: groups/categories, tiers, dated lists, agreements, simple promotions, named stacking plans/controls. ADVANCED: typed formulas, complex allowlisted conditions and governed conditional stacking. | Effect/applicability/result contracts now; optional configuration/UI slices later, not delivered by research. |
| P5b · Historical modes do not rewrite snapshots | ADVANCED draft depends on formula, company requests SIMPLE. | Hide controls leaves secret effects; auto-convert loses intent; impact reconciliation needs work. | Activation validates capabilities/configuration/duties and preview. Downgrade refuses unresolved dependent drafts, agreements/rules and pending approvals; reprice/resolve or withdraw under R5-approved lifecycle before switch. Archive incompatible future configuration; preserve issued/history read. | Dependency/mode evidence now; R5 transitions, R8 historical display, R9 mode duty. |

Optional modules may arrive later; dormant dimensions never affect calculation. A MANAGED rule cannot
silently run in SIMPLE. Common server controls remain enforced independently of whether advanced
configuration UI is exposed; lowering mode is not a means of evading active company obligations.

### P6 — Draft changes, protected explanation and connected commercial work

| ID / accepted basis | Unresolved decision and example | Options and trade-offs | Recommendation / why | Support now; later dependency |
|---|---|---|---|---|
| P6a · Draft stale review; immutable issue | Price list/category/membership changes after draft; manual net retained. | Auto-update changes intent; permanent freeze ignores eligibility. | Mark relevant dependencies stale; explicit before/after review/reprice or no-change confirmation against current valid facts. Retained manual net rechecks all controls. Snapshot evidence at issue; approval invalidates on relevant changed facts. | Fingerprints/version/provenance now; R5 exact draft/issue concurrency, R4 dates. |
| P6b · Server protects internal details | Customer wants discount shown, seller cannot read cost. | Show every candidate leaks personal prices/cost; net-only may hide agreed savings. | Customer-visible chosen commercial discount is explicit presentation intent; internal rules/formulas/cost/candidate terms projected by duty. Keep complete protected explanation for legitimate authorised review without exposing operands through exports/errors. | Separate projections now; R8 templates/delivery, R9 read/report authority. |
| P6c · Choices/partial-win are gated, 0109/0101/0102 | Customer accepts half discounted scope; returned goods need original terms. | Reprice from live lists rewrites offer; blindly prorate fixed-line benefit may over-credit. | Preserve offered scope and discount allocation/reference evidence; acceptance must validate thresholds and allowed combinations without editing issue. No policy for partial repricing/cancellation inferred here. Returns/refunds reference original sale/allocated benefits, never today's list. | Trace/line identity now; R6 partial/option acceptance, R7 order/job, R9 remaining/forecast, Phase 6 credits/refunds. |

### P7 — Quantity tiers, packages and repeated scope

| ID / accepted basis | Unresolved decision and example | Options and trade-offs | Recommendation / why | Support now; later dependency |
|---|---|---|---|---|
| P7a · Offered/priced/stock distinct, 0050/0080/0109 | 1–9 cartons `1000.0000` each, 10+ `900.0000`; carton = 10 units, loose unit also sold. | Always stock basis converts 100 loose units into carton eligibility; selling basis alone cannot express alternate thresholds; explicit rule basis avoids ambiguity. | Rule declares tier measure: offered packages, named pricing UoM/denominator, or derived stock UoM. Carton-only offer requires that package identity; 10 cartons qualify, 100 loose units do not unless expressly allowed. Tier threshold basis independent of rate basis, exact conversion evidence, no rounding into eligibility. | Measure/identity/tier intervals now; R4 precision; R5 snapshots; R6 selection. |
| P7b · Explicit independent logical scope | Two 6-carton lines versus choose-one alternatives each 6. | Always aggregate misprices alternatives; line-local is predictable but splitting changes tiers; selected-set aggregation needs acceptance reconciliation. | Initial LINE_LOCAL tiers: neither 6-line reaches 10; visibly state this, never silently pool. Reserve explicit SELECTED_COMPATIBLE_SET later, grouping same Item/package/basis/recipient/rule context and eligible selected scope only. Cross-Item/category and historical cumulative tiers deferred. | Aggregation discriminator now; R5 draft dependency; R6 option/partial acceptance must settle before aggregate delivery. |
| P7c · Quantity increment ≠ commercial minimum/multiple | Half carton violates package increment; 9 cartons misses price tier; MOQ 10. | One “minimum quantity” conflates price eligibility and ordering legality. | Package/stock increments hard validation under existing architecture. Tier minimum only nominates a rate; missing upper tier uses lower eligible rate. Sales MOQ/multiple is separately configured warn/block/approval, not inherited Purchasing supplier MOQ. Recommend defer Sales ordering controls until R6/R7, with explicit reserved semantics. | Separation now; Sales constraint delivery later; R6/R7 commitments/order validation. |

Recommend all-units breaks initially: 10 cartons at `900.0000` totals `9000.0000`; a marginal slab
(9 at `1000.0000` plus 1 at `900.0000` = `9900.0000`) is a different deferred effect, never inferred.
Intervals use declared lower-inclusive/upper-exclusive boundaries; 1–9 whole cartons is `[1,10)`.
Customer/group tiers obey P2 audience precedence; rates for loose sales are explicit, not automatically
carton rate divided by ten unless the selected rule permits normalisation. Reject incompatible UoMs.

### P8 — Minimal sales context, dates and currencies

| ID / accepted basis | Unresolved decision and example | Options and trade-offs | Recommendation / why | Support now; later dependency |
|---|---|---|---|---|
| P8a · Strict company boundary; organisation scope separate | Wholesale branch charges `900.0000`, online `950.0000`. | Geography/channel trees add burden; warehouse/department inference conflates operations/authority; optional flat context is enough initially. | Company-wide default. Reserve explicit flat sales-channel and selling-location references, enabled only for demonstrated use. Region conditions via an explicit later controlled dimension, no auto-derived address hierarchy. Reuse a valid existing location identity if its business meaning fits; do not equate warehouse, customer site or organisation scope to selling channel. | Typed extensibility/context evidence now; UI/entities deferred unless requested; R9 authorise context and scopes. |
| P8b · Dates/currency eligibility accepted; R4 authority | Price changes next month; USD list used for PKR quote. | Silent FX/date fallback conceals source; explicit eligible list and date preserves intent. | List carries currency and effective interval; selected list is explicit context, not an unlimited fallback chain. Within it P2 audience/source rules apply. Exact compatible-currency price initially; cross-currency requires approved R4 conversion/evidence, otherwise refuse. Future prices do not alter issue history. | Currency/date inputs and provenance now; R4 authoritative price date, timezone, boundary, FX/rounding policy; R5 revalidation. |

## Connected cases — architectural seams, not extra R3 engines

| Case / example | Settle now | Deferred owner / impact |
|---|---|---|
| Kit or bundle sold for one price | Priced parent and explanatory children cannot double charge (0109); retain source identity/cost evidence. | Kit master/stock expansion later; R5 source adapter, R6 acceptance dependencies, R7 fulfilment. No kit inferred from a section. |
| Buy 10 get 1 / free goods | Monetary zero has intentional promotion provenance, quantity/cost/control evidence; reward scope is distinct from discount. | Promotion/reward engine later, R6 selection and R7 fulfilment; never implemented by 100% ordinary discount shorthand. |
| Quantity/value commitments | Agreement identity distinct from fulfilment ledger; pricing selection neither consumes nor cancels commitment. | R6/R7 orders/acceptance/remaining commitments; later settlement. |
| Loyalty / retrospective rebate | Immediate benefit is a configured eligible adjustment; retrospective promise distinct from line net and optional future accrual. | Loyalty qualification/rebate settlement Phase 6 or later; no current net deduction for unearned rebate. |
| Services and Assembly work | Service Item can use Item/category/hour pricing; Assembly uses its work-UoM/group, not component categories; manual scope explicit basis, no text matching. | R5 supported cost/adoption; R7 actual service/job cost; no fake Item/Assembly/account. |
| Credits, returns and refunds | Retain original selected terms and allocations for later reversal. | Phase 6 accounting, later Sales returns; R6/R7 sale links. Supplier Return/Scrap remains closed and unrelated. |

## Owner review requested

Please review P1–P8 together, especially audience-first specificity, multiple flat groups, scoped
exclusions, conflict refusal instead of commercial ID tie-break, SIMPLE routine discounts, default
single-slot substitution versus permitted stacking, risk-exception foundation, line-local all-units
tiers and optional flat context. Numeric discount limits and approval audiences are company policy,
not invented global values. Exact permission vocabulary/schema/API/UI follow approved business
semantics; R4–R9 gates remain. Q43 blocks new pricing authority only, never unrelated C2/CRM delivery.

## Related

- [Expanded research](phase-4-r3-expanded-research.md) · [Initial evidence](phase-4-r3-sales-pricing.md)
- [Pricing domain](../domain/sales-pricing.md) · [Implementation-question reconciliation](../architecture/implementation-question-reconciliation.md)
- [Open Q43](../OPEN-QUESTIONS.md#q43--expanded-r3-sales-pricing-semantics)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Research method](../architecture/research-and-design.md)
