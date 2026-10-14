[← Phase index](README.md)

# R6 — Final C1–C8 customer acceptance decision review

**2026-10-14 · RESEARCH COMPLETE FOR OWNER REVIEW / PROPOSED / Q46.**
This is the authoritative final C1–C8 matrix, replacing the initial agenda at 325973f. **No new R6
recommendation is accepted.** R1–R5 remain governing; no ADR, application or delivery authority is
created. [Allocation and worked journey](phase-4-r6-allocation-and-journey.md) and
[evidence, identity and races](phase-4-r6-evidence-and-claims.md) complete the review.

Reuse accepted 0100/0109 scope/choice structure,0110 cost certainty,0115/0116 pricing and benefit-once,
0118–0120 financial evidence and 0122–0124 lifecycle,0070/0085 shared governance and 0101/0102 forecast.
The [dated primary comparison](phase-4-r5-quotation-lifecycle.md) already contrasts Dynamics frozen
quotes/order conversion with ERPNext partial quantity/alternative mapping (accessed 2026-10-12).
It supports separate offer/decision/target identities; vendor mapping does not decide our consent,
entitlement, statutory allocation or late-evidence rules. No broad research cycle is repeated.

## Single final owner matrix

| Bundle / accepted authority | New decision and examples | Options / trade-offs | Recommended direction for approval | Support architecturally now; defer execution / dependencies |
|---|---|---|---|---|
| **C1 — Eligibility**.0109 preserves source-specific measures, options and optional estimating;0124 preserves accepted/unaccepted scope. |4/10 cartons versus40% of a complete bespoke installation; independent service hours versus an indivisible outcome. | FULL_ONLY is simple but restrictive; unrestricted fractions misrepresent obligations; declared divisibility needs source validation. | Default FULL_ONLY per offered logical scope. DIVISIBLE only when explicitly offered and supported: compatible quantity/basis/increments, minimums, dependencies, applicable cost/technical allocation and consent all pass. Multiple independently acceptable scopes can be selected without making each scope fractionally divisible. Unsupported changes require reoffer. | Design source/quantity/entitlement and original-scope anchors now. Enable only supported adapters; Assembly/fixed setup splitting waits applicable R7 baseline rules. R5 snapshots/claims and R9 recording scope are prerequisites. |
| **C2 — Price and consent**.0115 line-local all-units tiers;0116 adjustments/contract limits;0122 immutable issued result. |100 committed with delivery40/60; only40 committed; an explicit partial entitlement retains9; otherwise ordinary rate10. | Always retain ignores minimum; automatic reprice lacks consent; direct acceptance variation adds another sensitive editing workflow. | Honour explicit partial entitlement only. Without it, receive a request/counterproposal, issue a new reoffer, obtain consent to its exact amount/conditions, then record acceptance. **Newly issued reoffer is the initial changed-terms vehicle; no acceptance-variation editor.** Never reprice already accepted terms or infer future60. | Keep original offer plus reoffer lineage/caps and exact consent. R5 whole replacement before acceptance can support reoffer; post-acceptance remaining reoffer stays gated by R6/R7 delivered contracts. R3 pooling/cumulative benefits and contract departure remain deferred. |
| **C3 — Choices/exclusivity**.0100 independent families/explicit exclusive groups;0109 within-quote choices/baseline. |A or B plus optional installation; standard/premium competing families; two staff select different options. | Auto-cancel competitors loses history; permit every combination doubles scope; choice claims need stable intent. | Accept only explicit offered, eligible selections and dependencies once. One alternative per within-quote choose-one intent; first authoritative partial binds that choice. First accepted claim in an exclusive family group binds the family; further disjoint acceptance in it may proceed within caps. Competitors become claim-blocked, not automatically cancelled/rejected. Selection changes after acceptance need governed correction/reoffer, not a toggle. | Stable choice/family/group claims across revisions and products now. Unsupported mixtures/conditional combinations refuse. R7 accepted cancellation/claim release and R9 hidden-member access; R8 displays honest availability/claim distinctions. |
| **C4 — Allocations**.0116 benefit identity once;0120 original components, rounding and computed/allocated base evidence distinct. |Fixed benefit100, fixed tax20, or0.03 split25%/25%/50%; a changed-price40 reoffer. |Repeat full benefit duplicates; independently round slices over-allocates; original allocation plus cumulative consumption is richer. | Original quotation allocation is historical attribution. Supported unchanged partials consume only entitled original benefits/component shares with cumulative caps and deterministic residuals. Fixed commercial benefit sharing must be explicit; no presumed proportional right. Fixed taxes require declared assessed-scope support, never generic legal proration. Reoffers preserve original attribution but separately calculate/agreed new amounts. | Preserve lineage, per-component budgets/consumption/remaining, policy and separate base conversions now. No multi-line header-discount input. New assessment/benefit needs explicit identity/authority; Finance legal taxes/credits and unsupported allocations later. |
| **C5 — Evidence/late records**.0123 separates expiry/withdrawal/rejection;0124 freezes predecessor history;0122 issuer acknowledgement isn't customer consent. |Customer PO/email/verbal agreement; claimed yesterday's acceptance recorded after expiry/replacement with competing commitment. |Current-only loses facts; arbitrary backdate bypasses claims; governed reconciliation needs evidence/review. | Ordinary acceptance requires current eligible offer, verified customer consent and recorder authority. Record occurrence and ERP recording times separately. Capture uncertain/disputed evidence as pending observation, not accepted business. A dedicated governed reconciliation may recognise proven earlier consent only after historical eligibility and present conflict/commitment review; no old-offer reopening or automatic later-claim reversal. | Evidence provenance and late-case identity now; company evidence policy supports legitimate non-electronic practice. Sensitive reconciliation duty separately approved in R9; R7 conflict corrections and R8 delivery/withdrawal evidence. Defer complex late-case execution if unsupported. |
| **C6 — Readiness**.0104 no universal CustomerAccount gate;0109 no mandatory Assembly/Job;R5 issue≠acceptance≠handover. |Customer accepts installation but site/Finance setup incomplete; stocked goods need delivery rather than a Job. |Force target creation delays real business; generic auto-job invents scope; separate accepted/readiness facts need visible follow-up. | Record authoritative acceptance once its own commercial/consent/claim requirements pass. Expose missing handover/site/operational/Finance prerequisites separately; no forced Assembly, Job or CustomerAccount. Accepted business is commercial commitment, not accounting revenue or completed work. | Accepted allocation/source and readiness interface now. R7 chooses target, multiplicity, baseline and correction; Phase5 execution and Finance posting later. Commercial uncertainty still prevents authoritative acceptance, not factual evidence capture. |
| **C7 — Identity/concurrency**.0100 atomic exclusive claims;R5 coordinator, scoped receipts and immutable evidence. |Same PO recorded by two staff with different keys; two40 slices; genuine later purchase of the same Item/quantity. |Receipt-only misses new-key duplication; amount/item matching blocks genuine sales; canonical decision case plus claims needs shared intake. | Stable company/Party customer-decision case independent of employee/key/quote revision. Customer references and versions resolve to that case; referenced amendments are not new purchases. No-reference cases need shared intake and duplicate review; unresolved identity remains pending. Atomically consume current offer and original-scope capacity plus choice/exclusive claims; receipts handle transport retry. | Shared business identity, authoritative caps and R5 writer coordination now; no magical dedup from UUID or text similarity. R7 handover has separate business consumption identity. R9 protects cross-record conflict details; current scope/field checks precede replay. |
| **C8 — Remaining/reporting**.0101 accepted versus remaining potential;0102 persistent provisional trust and explicit valuation FX. |40 accepted,60 merely potential/unoffered or later withdrawn; another independent installation scope remains. |Whole quote won/lost masks partial truth; summing revisions duplicates; automatic forecast refresh falsely implies trust. | Separate accepted quantities/agreed values, currently available/claim-blocked scope, sales potential, lost remainder, accepted corrections and actual fulfilment. A remaining quantity may exist without a current priced offer. Changes invalidate affected forecast trust, not automatically publish trusted values or close the Opportunity. | Distinct scope/value/event projections and original quote FX now. R9 final metrics/scopes and reporting FX; R7 delivery/cancellation facts separate. No old100 + accepted40 + remaining60 counted as200. |

All eight bundles require owner approval. The following sections make the defaults, consent vehicle,
source limits and R7 boundaries precise; they do not accept them by describing them.

## C1 — Source eligibility, not a universal “partial” switch

| Offered source / scope | Full acceptance | Partial allowed only when explicitly DIVISIBLE | Refuse/reoffer when |
|---|---|---|---|
| Ordinary stocked Item |Exact offered product/measure/conversions and conditions pass |Integer/declared divisible selling increments and exact compatible priced/stock quantities |Quantity changes product/package identity, minimum/price condition fails, unsupported conversion or missing mandatory evidence |
| Named package |Whole offered cartons/packs under captured contents and price basis |4 of10 whole cartons may qualify if explicitly divisible by carton |“Half a sealed carton” is unsupported under that offer; offer loose units through a legitimate new basis, not silent stock conversion |
| Measurable service |Offered service/deliverable, quantities and terms known |4 of10 genuinely independent hours/visits if minimum call-out/setup conditions supported |A single successful outcome or fixed mobilisation is presented as arbitrarily divisible |
| Assembly-backed work |Complete defined work with applicable issued technical/cost/material evidence |Independent zones/sqm/stages only with meaningful technical quantities, fixed/setup treatment and supported accepted-baseline allocation |40% of complete bespoke installation has no coherent delivered scope/estimate; do not proportionally scale a recipe into fictitious completeness |
| Controlled manual scope |Defined legal Party, nature, measure, scope and authorised terms |Explicit measurable divisible scope, real applicable cost/control evidence and consent |Free text alone does not define divisible units, excuse tax or create cost evidence |
| Optional/independent scope |Select whole optional installation or independent supply scope if offered/eligible |Its own scope must separately pass divisibility and prerequisites |Section heading alone is not an indivisible unit; optional installation dependent on equipment cannot be selected without satisfying declared dependency |

Recommend FULL_ONLY when an issued offer lacks an explicit partial declaration. This does not make
all lines compulsory: declared independently acceptable scopes/optional choices may still be accepted
whole. A chosen combination must have supported pricing/tax/control and review coverage; a baseline
approval is not authority for all unreviewed permutations. Arbitrary conditional combinations defer.
Offered actionable choices need the seller's required control/review coverage, or an explicitly
disclosed continuing approval condition under agreed policy. Recording cannot retroactively impose
an undisclosed new price/approval condition on genuine consent; capture that fact and reconcile a
discovered coverage gap rather than automatically repricing it.

Customer consent identifies exact revision/selection, quantity/basis, net/discounts, tax-estimate and
inclusion/withholding disclosures, contractual conditions and any allowed partial entitlement.
A price or commercial prerequisite change turns the request into a proposed new deal, not authorised
acceptance at a silently substituted price. If customer and appropriately authorised seller have
already genuinely agreed40 at9 outside ERP, do not relabel that fact merely to impose10. Preserve
their evidence and use supported governed recognition/reconciliation; no later reoffer invents consent
or rewrites an already agreed amount. Source truth/complete applicable cost remains0110/0116;
no universal new cost requirement for maintained/manual advisory paths.

## C2 — Exact initial changed-terms rule

| Situation | Terms remaining eligible / result recommended |
|---|---|
|Firm commitment100, delivery40 then60 |100-unit price may remain; delivery scheduling is R7, not a40-unit commitment. |
|40 only, original offer explicitly permits40 at9 and benefit treatment is defined |9 can remain with supported partial tax/allocation/controls and consent; do not infer this clause from an offered100 quantity. |
|40 only,100-unit minimum/benefit condition no longer met |Original offer remains immutable, but its100-unit entitlement is not available for this40 acceptance. Issue a reoffer with eligible terms, then obtain new customer agreement. |
|Customer requests B, new discount/payment condition or quantity beyond remaining cap |If the exact supported offered alternative/condition is already covered, validate C3; otherwise reoffer. No “accepted price” edit, implicit waiver or ordinary Issue-authority contract departure. |
|Customer already accepted100 or40 on valid agreed terms |Never recalculate its unit price from later purchases, cost, FX or a cancelled remainder. Amendments/cancellation require separately governed accepted-business and R7 correction policy. |

Do not rerun live price lists, FX or cost as an automatic repricing/voiding test for an unchanged
issued firm offer. Validate current actor/record/claim eligibility and **declared continuing conditions**
(such as an explicit contract-valid-at-acceptance clause), not an imagined requirement that every
old master version remain current. Original contract/rate expiry conditions must be explicit; no
universal assumption that a price-list change revokes a valid offer. New mandatory compliance/review
requirements need their own supported governance, never covert alteration of actual customer agreement.

Sending a reoffer is not consent. An earlier PO/email accepting40 at9 cannot evidence agreement to40
at10. Require a corresponding updated PO, signed/portal/email confirmation or policy-supported verbal
agreement to the new exact terms, recorded under C5. Company approval of discount is also not customer
consent. No new electronic-signature mandate or universal independent reviewer is proposed.

New reoffer before any acceptance can atomically replace the wholly unaccepted original under R5.
If reoffer covers40 rather than100, explicitly dispose of the old100 availability and show remaining60
as unoffered potential pending a new offer. Do not silently leave60 at the invalid100-unit price.
A later remaining60 reoffer after accepted40 is a **partially accepted-family operation**, even if no
old offer currently covers60: it cannot bypass R6/R7 gates by being labelled “new revision”.

## C3 — Claim semantics the owner is choosing

Within-quotation choose-one group: bind one selected option on first authoritative acceptance;
further eligible partials use that option. Optional independent scope has its own claim/dependencies.
Across competing families: bind the exclusive group to first accepted family, not merely to one quote
revision. Accepted40 can leave eligible60 in that family; it cannot permit competing family B to claim
the same pursuit. Other issued offers remain historical/offered records but normal acceptance is
claim-blocked, not automatically seller-withdrawn, rejected or customer-cancelled.

Independent families outside an explicit exclusive group remain independent under 0100. Source Item
identity is neither proof of duplicate intent nor proof of independence. Preserve copy/overlap anchors
and warn/review materially overlapping proposals without secretly making every family exclusive.
Membership/revision/recipient edits cannot release or evade an existing accepted claim.

Selection changes after acceptance, mixed A40/B60, moving accepted scope to a competing family or
releasing a group require explicit governed customer agreement and commitment compatibility, not a
selection toggle. Recommend refusing those corrections in initial normal acceptance delivery;
design references/events now and implement only after R6/R7 correction contracts are approved.

## C6 — Exact remaining R7 contracts, not a new R7 implementation

| R7 decision/interface required | Minimum acceptance-safe boundary | What may remain incomplete when acceptance is recorded |
|---|---|---|
|Accepted source/target mapping |Accepted allocation ID, logical/option identity and original/agreed issue lineage; Item/service/Assembly/manual retained |Order versus Job or another supported target not created; no mandatory fake Job/Assembly |
|Target cardinality and quantity consumption |Business handover intent, consumed/remaining accepted quantity, disjoint split/parent flow links |One/many targets not chosen until handover policy ready; no duplicate reuse of accepted40 |
|Delivery schedule versus new commitment |100 committed with40/60 scheduling stays100 accepted once |Delivery dates/slots/stock reservation pending; no extra acceptance for a delivery batch |
|Site/contact/operational readiness |Typed missing prerequisites and provenance/correction ownership |Execution-only site/contact details may wait; uncertainty about legal customer/scope/terms cannot |
|Technical/cost/material baseline |Source-appropriate accepted estimate/cost certainty, fixed/setup allocation and source control coverage |Current execution stock/estimate readiness may wait; unsupported partial technical allocation still blocks that authoritative partial |
|Commitment amendments/cancellation |Authorised accepted-business correction → target impact and exclusive/cap release protocol |Initial acceptance can exist without correction engine, but accepted40 cannot be replaced/cancelled through ordinary quote actions |
|Idempotent handover |Same accepted allocation/target intent returns same target; new key cannot consume it twice |Order/Job creation and their numbering/fulfilment are deferred R7 |

The target gate does not erase real customer consent. Expose ACCEPTED with readiness deficiencies
when its own commercial/evidence/claim prerequisites pass. Inadequate commercial identity, unsupported
partial economics or disputed consent remains pending/refused authoritative acceptance, regardless
of whether a Job could be created. Finance customer-account setup/invoice point is separately owned.
Site/contact facts that determine agreed scope, tax jurisdiction, price or authentic customer consent
are commercial prerequisites, not deferrable handover details. Execution-only access instructions,
scheduling/contact refinements or Finance setup may wait. No dummy data fills a mandatory gap.

## C8 — Distinct business facts

| Fact | Meaning / must not imply |
|---|---|
|Accepted quantity and agreed value |Authoritative selected commercial commitment, not recognised revenue or quantity delivered |
|Currently offered/available quantity |Supported live offer with current validity and claims; not all original unaccepted quantity |
|Remaining sales potential |Possible future business, which may have no active priced offer; MAINTAINED or eligible SELECTED_OFFERS under 0101 |
|Rejected/withdrawn/expired remainder |Disposition/availability of unaccepted scope; no accepted-business reversal |
|Accepted amendment/cancellation |Separate governed correction stream; original agreement and target impacts retained |
|Actual delivery/fulfilment |R7/Phase5 execution against accepted scope, never a second sale |

Affected forecast source changes leave0102 trust provisional until authorised review/publication;
use its explicit valuation-date FX, not live conversion or issue FX as published reporting FX. Do not
count unselected alternatives, blocked competitors or already accepted scope in remaining potential.
One partial acceptance never automatically makes the entire Opportunity won or lost. R9 settles
final metric formulas, access, exports and scope-safe aggregates.

## Support now versus staged delivery

Design coherent decision/consent/claim/original-allocation/readiness identities, typed source limits,
exact monetary evidence and protected company/record projections now. Initial acceptance should
support eligible unchanged full scopes and explicitly supported divisible cases after their contracts
are approved/delivered; everything else refuses, with factual pending intake kept distinct.

Changed-terms reoffers reuse R5; no separate acceptance-variation workflow initially. Late-sensitive
reconciliation, accepted selection/cancellation corrections, unsupported Assembly partials, advanced
conditional choices, new header discounts and Finance engines can be deferred with explicit refusal.
Partial remaining-scope reoffers/replacement stay blocked until both R6 and required R7 contracts
are approved AND delivered. Final R9 duties/grants and R8 evidence channels remain prerequisites.

## Exact owner disposition and coordination

Approve or amend C1–C8, especially FULL_ONLY default/declared DIVISIBLE scope, new issued reoffer
for changed terms, first-choice/family claim binding, explicit commercial entitlement and cumulative
allocation, governed late evidence and shared decision-case identity. No new architecture is accepted
by this local research commit. No demonstrated contradiction requires changing R1–R5 ADR bodies.

Q46 remains Origin: PLANNING RESEARCH, ready for final owner review. No implementation question
was forwarded. Future coding-origin questions retain original wording/context and Origin:
IMPLEMENTATION AGENT; only the coding owner can verify implementation. No application tests,
migrations, generated files, delivery-document changes or push are part of this work.

## Related

- [Worked journey/allocations](phase-4-r6-allocation-and-journey.md) · [Evidence/claims/races](phase-4-r6-evidence-and-claims.md)
- [Q46](../OPEN-QUESTIONS.md#q46--r6-customer-acceptance-partial-terms-and-decision-evidence)
- [R5 accepted lifecycle](phase-4-r5-business-review.md) · [R5 slices](phase-4-r5-implementation-slices.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Question workflow](../architecture/implementation-question-reconciliation.md)
