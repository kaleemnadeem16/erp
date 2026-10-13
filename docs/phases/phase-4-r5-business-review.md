[← Phase index](README.md)

# R5 — Consolidated quotation lifecycle decision review

**2026-10-13 · ACCEPTED L1–L6 architecture / Q45 CLOSED / no code authorised.**
Owner approved the recommendations after local6d5bc0c with explicit clarifications in
[0122](../decisions/0122-govern-quotation-candidates-adoption-and-revalidation.md), [0123](../decisions/0123-issue-numbered-quotation-revisions-atomically.md),
[0124](../decisions/0124-preserve-quotation-availability-and-scope-replacements.md). Alternatives below retain the reviewed decision basis;
R6/R7 partial pricing/allocation/consent/handover choices remain proposed, not accepted by R5.
[Lifecycle contract](../domain/quotation-lifecycle.md) and
[R5 slices](phase-4-r5-implementation-slices.md) are SPECIFIED, not IMPLEMENTED/VERIFIED.
[Evidence](phase-4-r5-quotation-lifecycle.md) and [R6/R7 journey](phase-4-r5-acceptance-and-handover-boundaries.md)
remain supporting research. [R6 C1–C8](phase-4-r6-customer-decisions.md) continues planning underQ46.

## Approved L1–L6 matrix — reviewed options retained

| Bundle / accepted authority | Genuine new choice | Practical example | Options and trade-offs | Recommendation for approval | R3/R4 and R6–R9 impact / delivery boundary |
|---|---|---|---|---|---|
| **L1 — Preparation and adoption**. 0109 sources,0110 cost certainty,0054/0058 plan controls; save/preview is not approval. | Where to capture adopted evidence and how to avoid unnecessary steps. | SIMPLE seller quotes10 stocked goods; MANAGED seller changes Assembly material mix. | Mandatory separate adopt/submit for every quote is burdensome; auto-adopt on save hides unreviewed facts; explicit review with a routine atomic issue path is simpler but needs an exact reviewed-result token. | Save editable inputs only; preview is non-authoritative. For no-approval/no-special-control cases, explicit Issue confirms the displayed current result and atomically adopts applicable evidence and issues. Controlled cases freeze a candidate for review, then approve/adopt at the required checkpoint. | R3/R4 calculations stay unchanged. Capture source-appropriate evidence; no material plan for ordinary Item/service/manual scope. R5 adapter needed now after approval; R9 duties, R6 chosen scope remain gates. |
| **L2 — Approval and freshness**. 0070/0085 hashes/review,0116 control separation,0117 deliberate refresh. | What the quote review fingerprint covers and when no-change revalidation preserves approval. | Qty, Party or FX source changes while total stays1000; a harmless internal note changes instead. | Invalidate on every edit wastes review; preserve on equal total misses risk; relevant canonical fingerprints plus explicit revalidation require careful dependency mapping. | Invalidate for any reviewed commercial/technical/routing/evidence change. Preserve only if both routing and review fingerprints remain identical, current eligibility passes and approval remains usable; append a revalidation receipt without rebinding old approval. | R3/R4 versions/allocations/FX/cost included. Routine issue duty distinct from discount/risk/contract approval; no universal maker/checker. R9 exact scopes/limits; shared engine adapted, not rewritten. |
| **L3 — Atomic issue and numbering**. Invariants/0079 issue-time allocation and configurable gaps; idempotent writes. | Official identity per revision, initial series default and quote-specific retry/issue boundary. | Two Issue attempts race; first commits but HTTP reply is lost. | Number drafts wastes official numbers; number at sending conflates delivery; reuse a family number with handwritten suffix complicates immutable allocation. | Internal family/draft IDs; each issued revision gets its own centrally allocated official number and separate family/revision references. Recommend ALLOW_GAPS default, company-selectable GAPLESS. Allocate inside atomic issue after checks; exact completed retry returns original issue/number. | R4 pricing date differs from actual issue instant; numbering date is0079 company-local issue. R8 PDF/send independent; R6/R7 no commitment from issue. Register Sales type/adapter after approval, no new counter framework. |
| **L4 — Availability and dispositions**. Immutable issue/0109, independent accepted/remaining0101. | Expiry representation, withdrawal/rejection/cancellation meaning and renewal. | An offer expires after40/100 units were accepted; seller withdraws remaining60 early. | One closed flag erases partial truth; editing expiry rewrites evidence; distinct availability and acceptance projections need clear UI. | Visible explicit valid-through date (company default, no universal duration), frozen local expiry boundary; expiry blocks normal new acceptance. Withdrawal/rejection close only unaccepted offered scope with reason/evidence. Draft cancellation abandons draft; issued seller cancellation is withdrawal. Renewal creates new revision. | R6 late decisions/commitment cancellation separate; accepted40 never erased. R9 remaining forecast becomes provisional as appropriate, no automatic lost Opportunity. Preserve events now; acceptance/corrections later. |
| **L5 — Revisions and replacement**. 0099/0100 family identity,0109 frozen baseline,0120 original allocations. | When old offer stops being actionable and how accepted/remaining scopes coexist. | Revision1 sent; revision2 drafted for remaining60 while acceptance of1 may race. | Close1 on draft creation loses valid offer; allow overlapping active revisions gives contradictory terms; atomic explicit replacement needs scope/claim coordination. | Draft2 leaves1 available. Issuing2 explicitly replaces eligible unaccepted scope in the same transaction. Within a family/revision chain, at most one actionable offer per overlapping logical scope; accepted portions retain original terms. Independent non-overlapping scopes can coexist. Initially refuse partial replacement until R6/R7 claim/allocation contracts are delivered. | No silent price refresh/second benefit or full fixed-tax repeat. R6 acceptance cap/alternatives/tier consent and R7 commitment amendments must inform implementation. Initial unaccepted whole replacement can be delivered after R5/R9 approval; partial paths later. |
| **L6 — Unknown estimates and usability**. 0110/0116 mandatory unknown refusal;0119 withholding≠gross;0120 net controls. | Can a complete quote issue with UNKNOWN withholding/payment estimate, and what disclosure/acknowledgement applies? | Net1000/output tax100/gross1100 known; customer cumulative withholding facts unavailable. | Block every unknown obstructs legitimate quoting; silently permit unknown misstates cash; governed opt-in preserves safety with modest disclosure. | Company policy BLOCK by default or ALLOW_WITH_ACKNOWLEDGEMENT for separate UNKNOWN withholding/payment only. Issuer acknowledges displayed consequence; customer-safe projection says gross1100, withholding/payment estimate not determined. Mandatory tax/FX/control unknown still blocks. | Does not waive tax/completeness/margin or create Finance settlement facts. R8 carries disclosure on artefacts; R9 manages policy/duty. Simple known quotes need no extra acknowledgement; advanced disclosures only when applicable. |

Approval of this matrix settles R5's business direction; it does not approve R6/R7 choices, R9 permission
names/grants, all tax methods or code authorisation. Accepted directions below clarify the matrix;
they do not grant new risk, agreement-departure, late-acceptance or commitment-cancellation powers.

## L1 — Practical preparation paths by source

**SIMPLE:** seller adds10 cartons at a maintained net rate100.0000, sees settled net1000.0000 and
supported tax, then saves. The draft has inputs and optional cached calculation, not approved evidence.
Issue is an explicit confirmation of the displayed economic fingerprint. If unchanged and every
applicable control passes, the Action records source/quantity/price/FX/tax/cost certainty and issue
in one transaction. No separate Adopt button, no artificial material plan, no approval when shared
mode/policy and source controls require none. Any changed result returns review-needed, not issue
at a price the seller never saw. Cached calculation is never an authorisation token.

**MANAGED:** an Assembly requires a material plan and seller changes its stock mix. Preview can
explore freely. A saved immutable *candidate* preserves proposed standard/planned differences and
pricing/financial facts for review but is not an adopted/approved plan. REASON_REQUIRED demands
reason; APPROVAL_REQUIRED waits for the specific independent decision; BLOCKED refuses the relevant
adoption/issue. The controlling source determines whether approval precedes adoption; it cannot
be delayed past a required adoption checkpoint. Once cleared, adopt applicable plan and issue after
revalidation; approval does not reserve stock. Sales-pricing MANAGED alone does not require approval.

| Source | Evidence needed at issue | What is not imposed |
|---|---|---|
| Direct stocked Item | Item/package/UoM factors, applicable Inventory-owned valuation and certainty, price/tax/financial evidence | Assembly standard/planned mix or material variance approval |
| Service Item | Declared service nature, selling measures, applicable authorised service cost where required | Stock quantity/cost treated as service cost or goods default |
| Assembly | Technical versions/measures, standard/planned requirements and adopted material/variance/stock-choice evidence where applicable | Reservation or permission to substitute unexplained live evidence |
| Manual scope | Declared nature/measure, authorised entered price and applicable real cost evidence/certainty | Fabricated Item/Assembly/customer account or invented cost to pass margin |

Cost-dependent pricing or mandatory controls require complete applicable cost. A maintained/manual
price with no applicable mandatory cost control may retain PARTIAL/UNKNOWN as accepted0116 allows.
Source neutrality never means every source has identical adoption prerequisites.

## L2 — Exact approval invalidation recommendation

Use two related concepts: a **freshness dependency vector** (changed generations, date passage,
source observations) and a **canonical approved-facts fingerprint** (what was actually reviewed).
Freshness changes trigger explicit revalidation; equal displayed net alone is not no-change.
Submission binds an exact immutable candidate/version on its revision; the eventual adopted/issued
snapshot references that candidate and its original source evidence. Copying into the issue snapshot
must prove equality, not replace the approved subject with a different candidate. A successor candidate
requires new review where approval applies. Routine no-approval issue may create its candidate atomically.
Both engine routing hash and 0070/0085 review-provider fingerprint must match. A generic audit log,
frontend dirty flag or header amount cannot substitute for either.

| Change | Approval consequence recommended |
|---|---|
| Logical scope/source/nature, offered/priced/package quantity or conversion, technical plan/variance/stock choice | Invalidate affected subject even if money equal; preserve old candidate/decision as history. |
| Base/rate/discount input, benefit identity/order/allocations, final override or effective limits/reference | Invalidate commercial review; recheck0116 duties/limits independently. |
| Pricing rule/rate/plan/formula/agreement economic version or applicability/context | Invalidate relevant review; cannot rebind an approval to an equal-valued successor term. |
| Cost certainty, applicable cost amount/source/version/quantity or controlled risk | Invalidate relevant cost/technical review even when selling total equal. A new observation timestamp alone may be a no-change receipt only if all reviewed identity/value/precision/validity facts truly match. |
| Tax classification/profile/exemption/component/rate/inclusion intent/basis/rounding or output/withholding state | Invalidate financial review/disclosures, including UNKNOWN→known changes if reviewed. |
| Currency, pinned FX value/date/source/route/policy; pricing/tax date; validity or payment/commercial terms | Invalidate relevant review even if converted net rounds equal. Issue instant observation is recorded separately, not a reason to hash the current clock on every check. |
| Responsible legal Party, relevant contact/place/context, issue-link policy/Opportunity association or baseline option | Invalidate where recipient, pricing, terms, tax, review or routing changes; Party identity never considered cosmetic. Optional associations without a relevant effect need revalidation, not forced repricing (0099). |
| Pure internal note or display reorder | No invalidation only if absent from review/routing and no allocation tie/choice/customer terms changes. Customer-facing description changes are reviewed scope changes. |

Shared approval modes remain NONE/POLICY/REQUIRED. NONE can remove ordinary quote review; it
cannot disable a mandatory source control or required exceptional discount/risk/contract approval.
REQUIRED without matching policy refuses. Issue authority itself never grants an exception duty.

No-change preservation requires unchanged subject/revision candidate and original evidence IDs/
versions, both canonical hashes identical, supported fresh prerequisites, current date/term/actor/
record eligibility and successful shared approval guard; responsible member/approver-routing
changes also invalidate where they change governance facts. Relevant version, provenance or review
content changing is **not** no-change even if total and rule percentage match. Append actor/time/
checked dependency receipt with the old candidate/approval references. Do not edit approved facts,
change its policy version or revive expired/rejected/invalidated approval; after invalidation the
same terms returning do not automatically restore the old decision.

Existing policy versions remain explanatory under 0070. Re-evaluate current mandatory approval
requirements at issue; a newly required subject cannot be ignored. Exact policy-revocation validity
and sensitive risk/contract subject adapters belong to R9/shared governance, not a silent new
universal “all policy changes invalidate all completed requests” rule. Step deadlines are distinct
from quote expiry; do not claim the existing engine supplies a generic post-approval valid-until.
Any such additional ceiling needs an explicitly approved subject policy.

## L3 — Atomic issue, retries and number consequences

Recommended issue command carries current actor, company, scoped revision, expected draft version,
reviewed economic fingerprint and idempotency key/hash. After current authorisation, completed exact
receipt replays the original issue result **before** new draft/stale checks. This remains the old
immutable revision even if later withdrawn, replaced or expired; replay is not reopening or redelivery.
Replay re-projects stored evidence under current sensitive-field/read eligibility; a cached privileged
response is not authority to disclose cost. Immutable issue facts remain original; current availability
may be reported separately. Changed intent under one key conflicts; a new command for an already issued revision refuses rather
than allocating a second number. Two same-key requests coalesce; different keys targeting one draft
serialize and only one may issue. Read history remains separately scoped.

For a new issue, lock family/scope claim and revision/lines/config dependencies in one published
stable order (R6's exclusive-group coordination included when applicable). Re-read facts, capture
one server issue instant, validate now-effective policy/term/availability/Party and approval, and
compare calculations against what issuer reviewed. Do not silently switch pricing date, FX or plan.
Append adopted applicable evidence, allocate number through 0079, freeze snapshot and append issue/
replacement events plus receipt atomically. Failure commits none of these business facts.

| Failure or retry | Required number behaviour under 0079 |
|---|---|
| Validation/refusal before allocation | No number taken. Allocate as late as practical. |
| Transaction fails after GAPLESS allocation | Counter/allocation/document roll back; uncommitted number can be reused by a later valid transaction. |
| Transaction fails after ALLOW_GAPS allocation | Sequence may advance; no issued document/allocation remains, gap allowed. Never lower/recycle counter to hide it. |
| Commit succeeded, response lost, exact authorised retry | Original issue/number/instant/evidence returned; no allocator call. |
| PDF generation or sending fails after issue | Issue remains valid with the same number. Retry artefact/delivery operation separately. |

Recommend one official number per issued revision, default template e.g. `QT-{SEQ:5}` with
ALLOW_GAPS, configurable through the existing framework. Family uses an internal immutable ID;
UI shows “revision2 of family …; replaces QT-00001” and both official numbers. Draft ordinal/identity
is not a number reservation. Alternative owner choice is one centrally allocated family number
with rigorously unique revision identifiers; it needs additional display/reference design and must
not concatenate unofficial suffixes while claiming every issue received an engine allocation.
No gapless-date ordering or new allocation-log UI is assumed delivered beyond 0079's actual state.

Issue freezes recipient/scope, approved decisions, applicable estimate/cost certainty, price/benefits,
financial components/FX/allocations, dates/policies, numbering evidence and customer-safe content.
Issue means official offer in this ERP, not PDF generated, emailed, delivered, signed or accepted.
R8 determines artefact readiness/delivery gates and immutable projections; do not guess that a failed
email rolls issue back. No issue automatically marks Opportunity won, reserves stock or creates a job.

## L4/L5 — Distinct events and availability

| Operation | Recommended meaning |
|---|---|
| Modify draft | New editable inputs/version; invalidate affected calculations/candidates/approvals. No change to issued predecessor. |
| Cancel draft | Abandon editable preparation with reason/history; existing issued offer remains available. Do not recycle an issued number. |
| Expire issued offer | As-of eligibility after frozen expiry boundary; original issue remains. No automatic customer rejection or reversal of accepted scope. |
| Withdraw issued offer | Reasoned seller decision removes only available unaccepted scope, checked against concurrent acceptance. Original terms and accepted scope survive. |
| Record customer rejection/loss | Record actual customer decision/reason and affected scope under R6; don't treat seller withdrawal as evidence of customer rejection. |
| Create revision | Copy/reuse inputs and source provenance into draft; re-evaluate deliberately. Original still available. Copied approvals are history references, not authority. |
| Issue replacement | Explicitly name predecessor/remaining scope and compare current claim generation; atomic new issue and old unaccepted availability removal. |
| Accept offer | Separate R6 authoritative customer decision and accepted quantity/choice/terms; issue/send never implies it. |
| Cancel accepted business | Separate R6/R7 governed commitment correction, not ordinary quote cancellation or reopening an issued row. |

Recommend a local date-only **valid through** field for initial quotes, with frozen time zone and
exclusive following-day instant. Valid through31 October means eligible until local1 November;
store enough evidence to keep that absolute boundary meaningful if company time zone later changes.
Require explicit validity later than the actual issue instant before issue, with a visible
governed company default rather than a
universal30-day rule. Unlimited validity or legal timestamp-specific offers require explicit later
policy support. Record actual customer-decision occurrence and recording times separately underR6;
no backdated “accepted before expiry” assertion used as ordinary late-acceptance bypass.

No contradictory active revision terms for the same remaining logical scope within one family.
ADR 0100 competing **families** may still offer alternative terms concurrently under their explicit
exclusive group; the one-actionable-revision recommendation does not prohibit that accepted model.
Independent non-overlapping
scopes may remain actionable; within-family options are frozen alternatives, not automatically
independent offers. Logical overlap/claim identity must survive line reorder/copies and changing
products; repeated Item IDs alone do not establish overlap. A fully accepted revision has no
available quantity to replace. Partial replacements must name original accepted and remaining
scope/allocations; until R6/R7 delivers those rules, refuse partial replace rather than copy100
when40 is already accepted. Do not release an exclusive-family claim on expiry/withdrawal.

If revision2 issue races with acceptance of1, both lock/check the same scope/claim generation:
acceptance first makes replacement stale and requires remaining-scope review; replacement first
makes ordinary new acceptance of superseded scope ineligible. No silent retry onto newer revision.
R6 must separately decide recording delayed evidence of an earlier customer decision; default
refusal is not a claim about legal validity of a customer's external acceptance.

## L6 — Unknown withholding and honest SIMPLE operation

Proposed company issue policy: BLOCK default, or explicitly enabled ALLOW_WITH_ACKNOWLEDGEMENT
for **only** separately unknown withholding/payment estimate. No arbitrary threshold or actor bypass.
An otherwise complete net/output/gross, valid FX and all required margin/floor controls are mandatory.
A known not-applicable withholding is distinct from UNKNOWN and needs no acknowledgement.

When allowed, screen and eventual R8 customer artefact show: “Commercial gross1100.0000.
Withholding and estimated payment are not determined; applicable deductions will be established
at invoicing/payment.” Do not show payable1100.0000, zero withholding or a guaranteed net receipt.
Issuer acknowledgement records actor/time, policy version, specific UNKNOWN state/reason and exact
reviewed result; invalidate on relevant changes. It is awareness, not customer agreement, approval,
exemption or risk permission. Mandatory contractual promise of exact payment would prevent this path.
Missing invoice/payment cumulative facts is distinct from unsupported quote output-tax calculation.

SIMPLE known quotes retain Save / Review / Issue with conditional warnings and no forced submit
screen. Settings and exceptional controls remain permission-aware and progressively exposed.
MANAGED agreements/tax contexts/material reviews appear only when applicable. Customer acknowledgement,
signature/delivery evidence is R6/R8, not invented by the issuer's checkbox. Finance later calculates
actual withholding/settlement; quotation cash estimate cannot post receipts or tax certificates.

## Recorded approval and remaining gates

The owner approved routine atomic adoption/issue, both-fingerprint no-change revalidation,
per-issued-revision numbering/company-configurable policy, explicit local validity and distinct
unaccepted availability, atomic scope replacement and BLOCK/opt-in acknowledged UNKNOWN payment.
Initial partial replacement remains refused until R6/R7 approved AND delivered. Validity duration
is governed company setup; numbering ALLOW_GAPS remains recommended default, not mandatory setting.

Cancelled drafts and withdrawn/superseded offers are not reopened by generic status editing;
use explicit successor revisions/events, preserving original terminal history. R6 corrections remain
separate, including late evidence of an external decision.

R6 still must choose partial-scope eligibility, original-tier retention versus changed-quantity price,
customer consent/variation form, alternative selection, fixed-benefit/tax allocation and late/rejected
acceptance corrections. R7 chooses Order/Job target/multiplicity and amendment/cancellation provenance.
The reconciliation recommends safe directions but does not accept them. A multi-line fixed
**document-level discount input** is not silently approved:0116 currently exposes per-unit/per-line
fixed discounts;0120 preserves allocation architecture. Any new header-level input/eligibility/duty
needs separate owner approval, whereas lifecycle now preserves its future once-only allocation seam.
R9 final duty/grant/limit
contracts and R8 documents remain gates. These are missing delivery decisions, not contradictions
requiring R3/R4 reversal. No universal approval or pricing/FX/tax change is proposed.

Governing R5 ADRs are now accepted and implementation contracts specified; separately authorised delivery
then implements source adapters, guards/transactions/evidence/DB protections, shared-engine adapters,
UI/demos and regression coverage. R6/R7 operations, Finance engines and communications stay later.
Nothing is IMPLEMENTED or VERIFIED by this research. Q45 is closed; Q46 tracks new R6 choices.
No coding question was forwarded.

## Related

- [R6/R7 reconciliation and journey](phase-4-r5-acceptance-and-handover-boundaries.md) · [Evidence](phase-4-r5-quotation-lifecycle.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Q45](../OPEN-QUESTIONS.md#q45--r5-quotation-lifecycle-checkpoints-and-issued-offer-treatment)
- [R3 slices](phase-4-r3-implementation-slices.md) · [R4 slices](phase-4-r4-implementation-slices.md)
- [Approvals](../architecture/approval-workflows.md) · [Numbering](../architecture/document-numbering.md)
