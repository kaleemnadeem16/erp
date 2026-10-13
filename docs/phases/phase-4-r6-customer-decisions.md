[← Phase index](README.md)

# R6 — Customer acceptance, alternatives and partial scope

**2026-10-13 · INITIAL BUSINESS REVIEW / PROPOSED C1–C8 · Q46, Origin: PLANNING RESEARCH.**
R5 is accepted in 0122–0124; R6 choices below are not accepted or coding authority. This bounded
review reuses [R5 partial/tier/allocation research](phase-4-r5-acceptance-and-handover-boundaries.md)
and [primary comparison](phase-4-r5-quotation-lifecycle.md), accessed 2026-10-12, rather than repeating
pricing/tax/lifecycle research. R7 is investigated only where commitment identity/readiness affects
acceptance. Next owner review must settle eligibility, consent, claim and allocation contracts before
R6 ADRs or enabling partial replacement. No code, legal tax engine or application checks here.

## Accepted baseline — new decision boundaries

0100 distinguishes independent families and exclusive competing groups;0109 freezes offered
choices/baseline while acceptance is separate.0115's tier is line-local all-units with declared basis;
no pooled/cumulative entitlement.0116 protects benefit-once, controlled exceptions and complete
applicable cost.0118–0120 preserve original financial/FX/component/rounding allocation evidence and
separate future invoice/settlement facts.0124 ends only unaccepted availability and refuses initial
partial replacement until R6/R7 approved/delivered.0101/0102 separate accepted business, remaining
potential, provisional trust and explicit reporting FX. Party, not fabricated CustomerAccount, is
legal recipient;0104 does not require every handover to create financial setup.

## Single initial C1–C8 review

| Bundle | Genuine new choice / small-business example | Options and trade-offs | Recommendation for owner review | Architecture/delivery and R7–R9 gates |
|---|---|---|---|---|
| **C1 Full/partial eligibility and consent** | Customer wants4 of10 boxes; bespoke Assembly quoted as one complete installation. | Full-only simple but restrictive; any fraction ignores package/technical dependencies; explicit divisible-scope rules need adapters and consent evidence. | Ordinary full acceptance binds unchanged eligible issued terms. Partial requires supported DIVISIBLE eligibility, exact compatible offered/priced/package measure, minimums/dependencies and explicit selected-scope/amount consent. Unsupported/indivisible scope requires new offer, never automatic scaling. | Design full/partial outcome identities now; enable only supported accepted contracts. R7 cost/estimate baseline for split technical/fixed setup; R9 record-on-behalf duty. No universal customer account requirement. |
| **C2 Quantity-tier consequences** |100 units quoted at9,40 accepted while ordinary price is10. | Always keep9 is convenient but may waive minimum; always reprice may break promised partial terms; full-commitment-only preserves economics but limits selling. | Respect explicit original entitlement: unchanged full100 commitment with delivery40 may retain9. Only40 committed cannot assume future60. Without explicit partial-rate retention, show new eligible quantity price and obtain customer consent through approved reoffer/variation vehicle before accepting changed amount. | Original issue stays unchanged; C1 consent and C7 claims. R3 cross-line/cumulative tiers still deferred; fixed contract departure remains unsupported without specific authority. |
| **C3 Alternatives and exclusive claims** | Customer selects B instead of baseline A, or adds installation. Two independent families compete for same sale. | Baseline-only simpler; free combinations can double count or bypass review; explicit supported choices require claim and financial checks. | Accept named offered selection once, exclude unselected options, validate dependencies/prices/controls/approval coverage. Initial choose-one group binds one selected alternative for that logical scope; mixed partial A/B requires explicit reoffer/selection rule. Different competing families can remain offers but one permitted exclusive commercial claim, even for partial first acceptance. | Immutable selection evidence and stable intent/claim key, not Item ID. R7 fulfilment scope; R9 opaque conflict if hidden competitor; no silent exclusive claim release on expiry/withdrawal. |
| **C4 Fixed benefits/tax allocation** | Document benefit100/fixed tax20 attached to original100; customer accepts40 then60. | Repeat full amount duplicates economics; naïve independently rounded proration over/under-allocates; original-scope conserved allocations are richer but explainable. | Preserve original line/scope allocation and cumulative consumed/remaining shares for supported divisible benefits. Do not grant new full benefit per acceptance/revision. Fixed tax uses declared assessed scope/method and explicit legal/context support; unknown prorating eligibility requires review/reoffer, never assume all duties proportional. | R4 computed-vs-allocated base and rounding evidence remain. New multi-line header discount input not authorised. R7 carries authorised shares; Finance legal invoice tax/credits separate with linked variance. |
| **C5 Expired/withdrawn/replaced offers and late evidence** | Customer says “I accepted yesterday” after seller records withdrawal today; another revision is now active. | Current-only misses genuine earlier decisions; arbitrary backdate bypasses claims; governed evidence capture/review has delay but preserves truth. | Ordinary acceptance only of currently eligible available scope. Separately capture actual occurrence and recording times plus authentic decision evidence. Earlier external decision needing late recording goes through a dedicated governed reconciliation duty/review; don't reactivate old offer or overwrite conflicting later claims automatically. | R5 frozen times/availability untouched. R7 conflicting target amendments and R8 withdrawal/delivery evidence dependencies; no generic expired-offer checkbox or claim that ERP status settles legal validity. |
| **C6 Acceptance vs operational commitment** | Customer accepts service40; Order/Job readiness or required site information not yet complete. | Force target creation in acceptance delays recording real business; auto-create generic job invents scope; separate accepted decision/target is clearer but needs readiness status. | Validate/record authoritative customer acceptance separately from Order/Job handover. Accepted business references exact scope/terms; target creation is a distinct idempotent R7 operation. Missing execution readiness is explicit, not no acceptance; unsupported commercial prerequisites still refuse authoritative acceptance. | R7 chooses target/multiplicity/cancellation, cost/setup/site baseline. No stock reservation/posting/Finance from acceptance; accepted business is not accounting revenue or forecast. |
| **C7 Duplicate prevention/concurrency** | Same customer decision sent twice with different retry keys; concurrent40/60 requests race with replacement. | Receipt-only misses fresh-key duplicates; amount matching rejects real repeat purchases; durable decision identity plus scope claims requires evidence and locks. | Use actor/company/operation receipt AND stable customer-decision/business allocation identity. Canonical customer reference or server-issued decision-intent identity plus evidence distinguishes retry from genuinely new purchase. Serialize accepted quantity/selection/exclusive/remaining claims with R5 coordinator and enforce DB conservation. | R7 separate handover intent/consumption identity. Different key cannot re-record same decision; simultaneous competing-family/overlap requests cannot both claim incompatible scope. No silent newer-revision retry. |
| **C8 Remaining disposition and forecast** |40 accepted, remaining60 later withdrawn or lost; other installation scope remains open. | Whole-quote won/lost masks partial facts; auto-publish forecast from new sums misstates trust; explicit remaining projection requires governance. | Keep accepted quantities/terms, remaining availability and remaining pursuit decision distinct. Expiry/withdrawal/rejection removes only relevant unaccepted availability; accepted cancellation/amendment separate. Changed forecast basis becomes provisional under 0102, never auto-trusted or double-counted. | R7 obligations preserved; R9 remaining/partial-win metrics and published FX/valuation. No automatic close/win entire Opportunity from one partial, family choice or handover. |

These recommendations settle the direction to review, not detailed schema or all conditional
combinations. C1/C2/C4/C5 need explicit owner choice on partial entitlement, revised-terms consent
vehicle, supported allocations and late evidence duty. Sensitive reconciliation permission must not
be inferred from ordinary quote preparation/issue/customer-recording authority. R9 finalises vocabulary.

## Two practical acceptance paths

**Small supplier, unchanged full offer:** a prospect Party accepts10 cartons at100.0000 net each,
known supported output tax10%, total gross1100.0000, within validity. Recommend explicit recorder
checking customer identity/evidence and exact issued selection/terms, then one authoritative full
acceptance referencing issue/currency/FX/component/policy IDs. Do not create a financial account,
material plan, Job or extra approval merely to record routine business when no policy requires it.
A repeated genuine decision returns the same acceptance; separate later Order/Job readiness is visible.
Changing quantities/options/terms moves out of this ordinary full-offer path.

**Conditional100-unit offer:** quote100 at tier9 with distinct allowed fixed-per-line benefit100 and
supported fixed quote-tax estimate20 gives net800.0000/gross820.0000. This mirrors the R5 numerical
journey; no multi-line document-discount input or statutory tax prorating is approved.

| Customer choice | Proposed consequence / unresolved acceptance rule |
|---|---|
| Accept100, delivery40/60 |9 price entitlement applies to accepted100 if stated terms permit; operational split is R7, not loss of tier. |
| Accept40 only with explicit partial-rate retention | Original attribution net320.0000/tax-share8.0000/gross328.0000 is possible only with supported eligibility/allocations and authorised consent. |
| Accept40 only without such entitlement | Recommend reoffer/governed agreed variation to10: net360.0000 + illustrative8.0000 =368.0000. Customer must accept the actual changed amount; original820 remains history. |
| Later revised remaining60 at10 | Presumed remaining benefit60 yields net540.0000 plus original-assessment share12.0000 =552.0000, only if eligibility/assessment continuation permits. A new genuine fixed20 assessment instead gives560.0000 with visible variance. |

Choosing the supported variation vehicle matters: safest initial path is a newly issued agreed
reoffer; a dedicated acceptance-variation operation is a new approved capability, not a generic
editable “accepted price”. Do not silently consume original40 claim through two separate offers.
R5 whole-unaccepted replacement can make a reoffer before any partial is accepted; after acceptance,
remaining-scope changes stay refused until R6/R7 contracts are approved and delivered.

C4 fractional-rounding illustration: original benefit0.0300, accept25%/25%/50%. Independent HALF_UP
shares0.01+0.01+0.02 incorrectly total0.04. A proposed supported cumulative-entitlement policy yields
settled cumulative0.01/0.02/0.03 and incremental0.01/0.01/0.01, conserving0.03. Final policy/ties/
eligibility must be named and approved; use original scope identity, quantum and remaining cap.
Never turn this example into universal tax or refund allocation. R4 original largest-remainder
allocation and new partial consumption are different stages with linked evidence.

## Identity, consent and authority facts to support now

Design accepted decision identity separately from issued revision and later fulfilment ID. Preserve
customer Party/contact or authorised decision source, actual decision occurrence/recording times,
source evidence/reference, selected offered scopes/options/quantities/UoM/conversions, original versus
agreed amounts/terms, variation reason/consent, entitlement/condition outcome, original allocations/
remaining caps, quote tax-estimate and pinned commercial FX, review/authority and claim generations.
No fabricated historic timestamp/approval/certificate or generic audit trail as sole evidence.

Full unchanged acceptance references original commercial/financial evidence; changing live list/cost/
FX does not rewrite its offer. Determine current recording eligibility and claim/policy prerequisites
separately. If current mandatory commercial risk/compliance policy requires fresh review, expose it
and obtain the required evidence/authority; don't change the customer's agreed net covertly or equate
an unknown execution estimate with no customer decision. Exact acceptance revalidation subjects/
review coverage belong to R6/R9 approval, not universal forced repricing.

Scope/choice claims use stable logical intent/overlap anchors across revisions, not product master
or display position. A25-unit alternative package and100-piece baseline may share intent but not
an automatically fungible quantity; supported comparison/basis is required before partial conversion.
Assembly splitting needs applicable technical/fixed/setup-cost support; no blind proportional recipe
or complete-cost assertion. R7 source adapter must reconcile accepted baseline without fabrication.

A sender/recorder's acknowledgement is not customer's consent. Signature, email, purchase order,
portal or witnessed instruction require an explicit evidence policy; no blanket compulsory e-signature
or maker/checker is proposed. Unsupported/disputed late records may be captured as pending factual
observations for governed reconciliation, without asserting authoritative accepted claims or operational
commitments. R8 communications context assists evidence, never decides legal binding automatically.

## R7 dependencies bounded to acceptance safety

R7 must define accepted allocation→target identity, source-appropriate Order/Job paths, one-vs-many
or disjoint splits, readiness, contacts/site, cost certainty/material baseline and correction ownership.
One accepted commercial scope is counted once even when Order→Job→fulfilment links exist. Target
idempotency plus business consumption identity prevents new-key duplicate handover. A cancelled Job
cannot automatically erase customer acceptance/release exclusive claim; correction requires governed
commercial and operational evidence. Phase5 execution and Finance invoices/settlements stay later.

R6 should not require a Job for ordinary stock/service acceptance, nor erase accepted business merely
because execution prerequisites remain. Partial replacement enabling needs both R6 claim/consent/
allocation support and R7 commitment compatibility; architecture approval by itself is insufficient.

## Next review priorities and coordination

First settle C1/C2 consent/quantity/tier cases, then C3/C7 stable choice/exclusive/decision claims;
C4 fixed allocations and C5 late evidence follow with exact numerical/external-decision scenarios.
C6/C8 clarify recording/readiness and remaining metrics with bounded R7/R9 implications. No broad
research cycle unless a specific unresolved design conflict appears. Prepare final consolidated R6
review before ADR acceptance; no R6 decision becomes accepted through this planning commit.

Q46 is new PLANNING RESEARCH, separate from closed Q45. Future owner-forwarded coding questions
retain Origin: IMPLEMENTATION AGENT, original wording/context and coding-owned verification under
the accepted workflow. No coding question was forwarded, answered or marked VERIFIED here.

## Related

- [R5 accepted review](phase-4-r5-business-review.md) · [R5 slices](phase-4-r5-implementation-slices.md)
- [Lifecycle contract](../domain/quotation-lifecycle.md) · [R6/R7 journey](phase-4-r5-acceptance-and-handover-boundaries.md)
- [Q46](../OPEN-QUESTIONS.md#q46--r6-customer-acceptance-partial-terms-and-decision-evidence)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Job costing](../domain/job-costing.md)
- [Question workflow](../architecture/implementation-question-reconciliation.md)
