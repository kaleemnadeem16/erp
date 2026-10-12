[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R4 — Accepted currency, tax-composition and rounding decision matrix

**2026-10-12 · ACCEPTED F1–F8 architecture; Q44 closed by ADRs
[0118](../decisions/0118-pin-quotation-pricing-dates-and-company-base-fx.md), [0119](../decisions/0119-determine-versioned-quotation-tax-components.md),
[0120](../decisions/0120-settle-quotation-rounding-and-financial-evidence.md).** The option comparisons below retain the reviewed decision basis;
recommended directions are now accepted with the owner refinements recorded in those ADRs.
[Typed financial contract](../domain/quotation-financial-calculation.md) and
[R4 slices](phase-4-r4-implementation-slices.md) are SPECIFIED; no code or tax engine is implemented/
verified by planning, and no R3/R4 coding authority is granted. Multiple simultaneous taxes,
exemptions and withholding are core R4 architecture; execution may be staged.
[Tax model and worked examples](phase-4-r4-tax-composition.md) and
[evidence/repository/readiness reconciliation](phase-4-r4-evidence-and-readiness.md) support this single
matrix. Reuse accepted R3, accounting/FX and 0056 tax research; fresh primary comparison addresses
only unresolved boundaries. Figures illustrate configuration, never a country's law or actual rates.

## Baseline at initial review — implementation unchanged by acceptance

[0012](../decisions/0012-multi-currency-dual-amounts.md): currency/FX/base amounts on monetary rows,
`decimal(19,4)` money, immutable historical conversion; [0031](../decisions/0031-manual-exchange-rate-entry.md):
manual rate entry only. Existing global currency metadata includes decimal places; company-scoped
rate records have dated pair/type/source and nine-place values. [0081](../decisions/0081-central-company-profile-and-company-wide-settings.md)
owns base currency/time zone and financial-activity lock; no second accounting time-zone setting.
[0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md)/0080/0109 own units/packages.

[0025](../decisions/0025-configurable-tax-engine.md)/[0056](../decisions/0056-progressive-tax-calculation-and-recovery.md)
already accept data-driven tax, multiple/inclusive/compound/fixed/quantity components, classifications,
exemptions, tax-on-tax, separate payment withholding, recovery distributions and quote estimate versus
invoice legal determination. BASIC/LOCALISED/ADVANCED tax modes are independent of pricing modes.
These are accepted designs, not an implemented transactional tax engine. A nullable CustomerAccount
`tax_code_id` remains an unusable forward placeholder; non-null input is prohibited. No reviewed tax
component engine/master surface exists. Existing PO amounts/rate entry do not settle Sales policies.

[0115–0117](phase-4-r3-business-review.md) approve R3 precedence, SIMPLE discounts, final-net controls,
mode/evidence and four selling sources, explicitly gating dates/FX/tax/rounding through R4. Their
accepted bodies remain unchanged. Detailed findings/conflicts are in the readiness record.

## F1 — Pricing dates, eligibility and changing drafts

| ID / already accepted | Exact new choice and numerical example | Options / trade-offs | Recommendation and reason | Foundation now; later owner / R3 dependency |
|---|---|---|---|---|
| F1a · Company time zone, effective rules, immutable issue | Which date selects 1000 price ending 31 October and 1100 from 1 November? | Draft creation date freezes eligibility; live today changes silently; explicit date is reviewable but needs refresh. | Explicit pricing date, default current company-local day at creation/deliberate reprice; freeze chosen date/result until explicit review. Keep pricing date, quote/expiry/issue date and tax-estimate date separate. Issue rechecks current term eligibility under R5; old pricing date cannot evade expired selected agreement. | Date fields/gates now; R5 refresh/issue/expiry semantics. PR0–PR3, PR5/PR6. |
| F1b · Dates need explicit boundaries, no UI-order inference | Adjacent versions valid through October and from November must not both match midnight. | Inclusive end user-friendly but easy timestamp errors; half-open internal interval avoids overlap. | Date-only commercial effective periods `[start, end_exclusive)` interpreted under captured company time zone. UI “valid through 31 October” stores exclusive 1 November. Timestamp observation is UTC; don't reuse UI expiry time as rule interval. Date passage/new eligible versions enter dependency evaluation even without master edits. | Interval constraints/generation and time-zone snapshot now; R5 lazy/read-only stale detection and before-write checks, no scheduler/code authorised. PR0–PR3. |

Future-date prices may be previewed at an explicit future pricing date; they do not supply future
Inventory valuation. 0110 unsupported historical/future cost remains unknown, so applicable mandatory
cost-derived/control paths still refuse. Historical issued prices and date/time-zone evidence never
move with master changes. R5 must decide when a new offered revision may use earlier pricing terms.

## F2 — Document currencies, conversion and rate freshness

| ID / already accepted | Exact new choice and numerical example | Options / trade-offs | Recommendation and reason | Foundation now; later owner / R3 dependency |
|---|---|---|---|---|
| F2a · Transaction/base/display distinct; CustomerAccount optional | PKR company, USD100 maintained rate, eligible prospect requests EUR quote. | Require same-currency list is simplest; direct/inverse/arbitrary chains introduce ambiguity; explicit base bridge is reproducible. | One document currency; Party/account preferred currency is a default, not authority or requirement. Same-currency pricing needs no price conversion; transaction→base still needs evidence. Cross-currency list must expressly permit conversion; initially use explicit company-base bridge, not arbitrary cheapest path or silent override of fixed agreement currency. | Source/document currency + conversion allowance and separate price FX/base FX fields now. R3 PR0/PR1/PR3/PR5 schema; R9 currency/override duty. |
| F2b · Dated manual SPOT; rate provenance pinned | USD→PKR280, EUR→PKR350: USD100 → EUR80 → PKR28000. Direct USD→EUR0.81 would yield EUR81/base28350. | Best available route changes commercial result; named direct route can be valid but needs explicit policy; base bridge makes dual amounts coherent. | Price conversion via one pinned set: source→base and document→base, derive source/document ratio with rational precision. Both legs share company/requested date/type, each records actual date/source/value; any permitted prior-date leg remains explicit. Identity leg is exact1 with identity provenance. Do not round intermediate PKR or derived ratio prematurely. Defer alternate routes until approved. | FX route/evidence/conservation now; future direct route requires explicit new policy, not silent fallback. PR0–PR5. |
| F2c · Prior-rate fallback documented; no automatic FX fetch, 0031 | Requested Monday; most recent manual rate Friday. | Unlimited prior silently stale; exact-date blocks weekends; bounded prior with explicit policy balances operations/risk. | Quotation SPOT at explicit requested pricing/valuation date; latest-on-or-before only under company maximum-age policy, show actual date/age. Default exact-date unless carry-forward configured. Missing/over-age refuses conversion/mandatory evaluation; no1/default currency/provider substitution. New or corrected relevant rate triggers draft review, not rewrite. | Age/selection/provenance contract now; quoted FX != forecast valuation FX (0102). Refines unbounded domain fallback for quotation use under accepted 0118. PR1/PR4/PR5; R5 approvals, R9 authority. |

Rate masters currently permit authorised edit/delete. Pin value/pair/date/type/source/observation,
not just rate-row ID; fingerprints detect relevant correction/replacement. Append-only document
snapshots survive master deletion/correction. No new FX-maintenance/import/revaluation workflow is
inferred. Future invoice and receipt dates select their own rates under accounting authority, while
contract currency/amount remains traceable; quote FX is not automatically settlement FX.

## F3 — Tax determination, classifications and exemptions

| ID / already accepted | Exact new choice and numerical example | Options / trade-offs | Recommendation and reason | Foundation now; later owner / R3 dependency |
|---|---|---|---|---|
| F3a · Dedicated tax classification, Party profiles, declared location/date; 0056 | Goods1000 has taxes10%+5%; service500 has5%; exempt Item200 has0: net1700/tax175/gross1875. | One document tax code cannot express differences; use line classification with versioned multi-component plan and document context. | Per-line classification/tax disposition and explicit jurisdiction/place-of-supply facts; rules choose eligible plan/components/rates. Item SERVICE uses service-appropriate default, Assembly work its explicit/Group/service default; manual line requires declared nature/classification or explicit non-applicability. Packages change quantity evidence, not jurisdiction by themselves. | Party-based profiles/no financial account prerequisite, classifications and tax determination now; proposed service/default extension to 0056 must be recorded if approved. PR0/PR1/PR5 source adapters; R9 configuration/override. |
| F3b · Zero/exempt/not-applicable distinct; customer opt-out accepted in 0025 | Customer exemption removes10% component on1000, but5% levy still50. | Blanket zero-all taxes easy but legally/financially ambiguous; component-scoped evidenced outcome preserves unaffected levies. | Distinguish TAXABLE, ZERO_RATED, EXEMPT, NOT_APPLICABLE and unresolved; profile/certificate validity and component/jurisdiction scope determine exemptions. Company/customer tax-off is explicit reasoned configuration under 0025, never missing setup or a label. Missing necessary context/rate/dependency blocks complete estimate/issue; configured zero is complete, missing is not zero. | Certificate/exception/reference/time and declared exemptions now; actual statutory proof/validation/templates later localisation, R5 issue, R9 protected profiles and authority. |

Tax follows approved discounts/line allocations in ordinary configured taxable-net cases. Jurisdictions
requiring pre-discount/deemed value use an explicit supported statutory basis/provider with evidence;
never silently use price-list gross or assume free-goods are untaxed. Unsupported basis refuses rather
than producing a plausible percentage. Jurisdiction is not Sales channel or record scope. CORE is
configurable mechanism; no Pakistan-specific threshold/rate/classification inferred.

## F4 — Multiple components, dependency bases and mixed inclusion

| ID / already accepted | Exact new choice and numerical example | Options / trade-offs | Recommendation and reason | Foundation now; later owner / R3 dependency |
|---|---|---|---|---|
| F4a · Multiple and tax-on-tax accepted in 0056 | Net1000: independent10%+5%=1150; second5% on net+first tax=1155; second5% on first tax only=1105. | Implicit row-order subtotal is fragile; Boolean “include prior taxes” too coarse; explicit named dependency graph costs more but explains each basis. | Versioned tax groups/templates choose components with typed bases NET, TAX_AMOUNT(component IDs), NET_PLUS_COMPONENTS and declared quantity/fixed scope. Stable IDs, validated acyclic graph, topological order independent of presentation. Validate compatibility/duplicate statutory component/missing dependencies and per-component exemptions. | Full component/composition contract now as R4 core; BASIC/LOCALISED/ADVANCED execution remains 0056-gated, not all modes enabled. PR0/PR4/PR5 shape; Finance postings later. |
| F4b · Fixed/quantity tax accepted; offered/stock/priced independent | Fixed10 once per line vs 2 per stock unit on20 units gives10 versus40; 10% on net+40 gives104 tax. | Generic amount/“quantity” misreads units or duplicates document levy; typed fixed scope/UoM avoids it. | Separate PERCENTAGE, FIXED_PER_LINE, FIXED_PER_DOCUMENT, AMOUNT_PER_DECLARED_UNIT; positive denominator, explicit currency/UoM/conversions. Document fixed amount assessed once then allocated, not multiplied per line; commercial freight fee remains a charge, not tax because a template contains it. | Methods/scope/allocations now; configured levy execution under 0056 ADVANCED/localisation later. PR0/PR3/PR5 quantity/evidence; R6 partial, Finance reversal. |
| F4c · Inclusive back-calculation separate from pricing formula | Entered1100 includes10%, plus exclusive5% on net: net1000/taxes100+50/payable1150; compound extra55 gives1155. | One blanket inclusive flag cannot distinguish components; reinterpret R3 net silently breaks fixed terms; explicit amount intent avoids that. | R3 canonical price remains tax-exclusive net. Default NET_FIXED; optional gross-fixed input explicitly names included components and uses separate tax-normalisation adapter. Solve supported affine graph for net, then add excluded components; unsupported/cyclic/ambiguous inversion refuses. Never sum percentage rates for compounded taxes. Gross-fixed versus net-fixed exemption behaviour explicit. | Net/gross input intent, inclusion-set/effective plan and inversion/evidence now; extends R3 schema through its R4 gate without changing canonical net contract. PR1/PR3/PR4/PR5; R5 approval fingerprint. |

For fixed inclusive1100/10%, net1000; with applicable exemption, NET_FIXED yields1000 payable,
GROSS_FIXED may retain1100 as net/payable under explicitly agreed intent. Configuration cannot silently
choose one. Agreement inclusivity/currency must be expressly declared; “no extra commercial discount”
does not answer whether taxes are included. See worked-example record for inclusive additive versus
compounded and dependency-on-exempt-component outcomes.

## F5 — Withholding and payment estimates

| ID / already accepted | Exact new choice and numerical example | Options / trade-offs | Recommendation and reason | Foundation now; later owner / R3 dependency |
|---|---|---|---|---|
| F5a · Receipt/payment withholding separate from invoice/output tax | Net1000+output100=gross1100; 5% withholding on net=50, expected cash1050; on gross=55, cash1045. | Negative Sales tax changes gross incorrectly; separate payment estimate preserves revenue/tax; actual threshold histories require Finance. | Separate WITHHOLDING treatment/rule group, declared net/gross/payment basis, effective rate, profile/certificate evidence and ESTIMATED/UNEVALUABLE. It never reduces commercial net/output tax/gross or boosts margin. Quote may show estimated cash bridge, not receipt/credit/payment allocation. If actual cumulative facts unavailable, do not claim exact expected cash. | Withholding architecture/evidence core now; Finance invoice/receipt allocation, remittance/certificate/threshold histories later. PR0/PR5 result shape; R8 labels, R9 read/authority. |

Withholding may be triggered at a different legal point in future jurisdiction packages, but accepted
core 0025/0056 remains settlement-separate. A module needing invoice-stage liability must obtain
explicit localisation/accounting refinement; don't implement vendor deduction rows as output taxes.

## F6 — Precision, commercial rounding and allocation

| ID / already accepted | Exact new choice and numerical example | Options / trade-offs | Recommendation and reason | Foundation now; later owner / R3 dependency |
|---|---|---|---|---|
| F6a · Money19,4; nine-place rates/factors; no financial float; 0055 unit boundary | Three lines each qty3 at0.3350: unit-to2 gives3.06; line-to2 gives3.03; aggregate-to2 gives3.02. | Round everything to currency early loses small rates; final-total-only mismatches visible lines; named boundaries reconcile. | Preserve monetary storage4 and rates/factors9; exact decimal/rational intermediates, never float. Default unit-price boundary4 then final commercial line net at currency minor units, HALF_UP; discounts resolved before final net rounding with trace. Document net=sum settled line nets, no second hidden net rounding. Other unit policy explicit/versioned, not display setting. Refuse unsupported >4 amount precision/range pending approved extension. | Arithmetic contexts/rounding policy/trace now; 0012 preserved, domain “presentation only” wording needs reconciliation. PR0–PR5; R8 format. |
| F6b · Tax rounding boundaries allowed in 0056 | Three0.05 lines at10%: line tax0.01 each=0.03; group basis0.15→tax0.02. | One universal default may violate configured regime; global amount without line allocation breaks credits; component groups differ legitimately. | Default per-line/component currency rounding; support explicitly versioned aggregate-per-tax-identity/rate/jurisdiction grouping, then deterministic allocation. Compound basis declares rounded or unrounded predecessor, no assumption. Never aggregate different rates/exemptions. Cash rounding separate payment adjustment, not quote net/tax. | Stage/precision/rounding/grouping per tax component now; law-specific settings later, R5 snapshot, R6 allocations, Finance cash/GL. PR0/PR4/PR5. |
| F6c · Dual money and immutable line evidence | Equal100/100/100 lines share1 discount:0.34/0.33/0.33, not0.33 each; converted line sums can differ from total conversion. | Last line arbitrary depends on display order; proportional largest remainder with stable scope identity reproducible. | Proportional eligible-net allocation with largest remainder at currency quantum; tie logical line ID, cap at eligible amount. Preserve raw shares, residual and policy. Base components rounded once to storage4; choose/document authoritative converted total with separately recorded conversion residual/allocated contributions. Preserve computed base amounts and commercial net/tax. | Allocation/dual-money conservation now; zero eligible base refuses allocation, not equal spread; R6 partial and R7/R8/Finance adoption need original IDs. PR0/PR4/PR5. |

Gross-fixed inclusive rounding must conserve declared included gross = settled net + included tax,
with explicit permitted extraction residual and final control recheck; unsupported reconciliation
refuses. No tax/price mutation to force a desired total.

Quantity rounding is a separate hard representability check: offered measure and exact conversion
must meet accepted package/stock increments; derived stock quantity must fit ledger4 without silent
scope change. Proposed offered precision initially4; technical estimating adapter may retain its
existing precision but cannot silently truncate into commercial line/stock measures. Rational pricing
quantity (e.g. per1000 units) need not be independently rounded before amount multiplication. Existing
nine-place converter precision must be recorded and its supported tolerance specified before trusted
Sales use; do not assume a rounded conversion is mathematical exactness. No global UoM rewrite proposed.

## F7 — Final-net margin/floor comparisons and FX risk

| ID / already accepted | Exact new choice and numerical example | Options / trade-offs | Recommendation and reason | Foundation now; later owner / R3 dependency |
|---|---|---|---|---|
| F7a · 0116 independent final-net controls, no initial bypass | EUR100 at base350 gives35000; complete base cost28000 gives20% margin. FX340 gives34000, margin≈17.65%. | Compare raw before rounding hides actual breach; tax-inclusive gross boosts margin; final allocated tax-exclusive net is comparable. | Evaluate after commercial/discount allocations and settlement net rounding, before tax/withholding; gross-fixed input normalises first. Compare in pinned company base currency and same priced quantity, without using rounded display unit or known subtotal. Floor and margin separate; recheck any later approved net-affecting adjustment. Rounding is not tolerance to pass mandatory failure. | Control evaluation basis, source-versus-quote FX and cost date/coverage now. PR4/PR5; R5 reprice/approval, R9 blind status/reference duties. |
| F7b · Complete cost for derived pricing; partial/unknown explicit | Maintained service5000 has no supported cost; known materials3000 plus missing labour isn't total3000. | Use subtotal as denominator false; refuse all maintained prices over-restricts. | Propagate COSTED/PARTIAL/UNKNOWN and time/basis coverage from 0110; maintained/manual can proceed absent mandatory cost requirement. Missing comparable FX/cost yields UNEVALUABLE mandatory refusal. Tax/FX permission cannot grant stock/recipe read or risk bypass. | No new valuation mode; current Inventory query cannot answer arbitrary future/historical cost. PR0/PR4; R5 adoption and R7 actual cost. |

Output taxes collected for authority and withholding are not selling revenue/cost adjustments. Accepted
input-tax recovery remains a Finance/Purchasing cost-source responsibility; do not invent extra cost
or reclaimable tax in Sales control denominator. Any levy genuinely part of seller cost needs explicit
Finance-backed cost evidence, not classification as tax alone.

## F8 — Snapshots, partial scope and later documents

| ID / already accepted | Exact new choice and numerical example | Options / trade-offs | Recommendation and reason | Foundation now; later owner / R3 dependency |
|---|---|---|---|---|
| F8a · Immutable issue; invoice legal tax independently determined, 0056 | Quote1000+tax100 later invoice legal rate12% could require120; copying100 blindly is wrong. | Copy all terms avoids drift but ignores tax point; recalc everything loses agreement. | Separate adopted commercial terms/allocations/FX from invoice statutory determination and settlement FX. Preserve original and new evidence/link plus visible variance, not edit issued quote. Model net/tax/gross/expected cash separately in both currencies, input inclusion intent, basis/rule/date/certificate and rounding/allocation versions. | Snapshot envelope and source links now; R5 exact persistence/issue, R6 consent if payable changes, R7 baseline, R8 projection, Finance legal tax/FX/posting. PR5/PR6. |
| F8b · Partial/options/remaining scope gated | Half of fixed10 line levy/1 document discount cannot be independently rounded then over-refunded cumulatively. | Naive prorate cheap but may exceed original; original allocation ledger supports conservation without settling legal allocation now. | Preserve original line/component allocations, assessed quantity/fixed scope and cumulative remaining references. Acceptance/credit allocation uses original identities and caps remaining original amounts unless a separately authorised legal correction/re-determination requires variance. Recheck eligibility, never edit offer or pool alternatives. | Evidence seam now; exact partial levy/discount/tax/FX carry and customer agreement remains R6/R7/Finance, R9 reports. No refund/credit engine in R4. |

## Historical owner decisions — accepted in 0118–0120 and schema impact

The owner approved F1–F8 as one calculation architecture: explicit dates, authorised conversion/age,
classification/exemptions, tax dependency/inclusion model, separate withholding, named rounding/
allocation and final-net controls. Prioritise F2 currency/rate evidence, F4 net/gross intent and tax
components, F6 precision/residual contracts: these directly affect PR0–PR5 schema and amounts.
Maximum carry-forward age and actual tax/rounding regimes are company configuration, not invented
universal values. No mature ERP default or example percentage becomes jurisdiction law.

Approved refinements are recorded in 0118–0120: quotation-bounded prior FX fallback;
operational rounding versus old multi-currency prose; service/manual classification default extension
and precise inclusive adapters under 0056/R3. No demonstrated contradiction requires changing accepted
R3 principles; treating net amounts as inclusive or assuming one tax would violate them. No code is
independently authorised; readiness details show what is contract-independent, not permission to start.

## Related

- [Tax composition/examples](phase-4-r4-tax-composition.md) · [Evidence and PR readiness](phase-4-r4-evidence-and-readiness.md)
- [Phase index](README.md) · [Phase 4](phase-4-sales-quotations-and-handover.md)
- [Q44](../OPEN-QUESTIONS.md#q44--r4-currency-tax-composition-and-rounding-contracts)
- [Tax domain](../domain/tax.md) · [Currencies](../domain/multi-currency.md) · [R3 slices](phase-4-r3-implementation-slices.md)
