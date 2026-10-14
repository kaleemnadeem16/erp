[← Phase index](README.md)

# R6 — Customer consent evidence, decision identity and claim races

**2026-10-14 · C3/C5/C7 architecture ACCEPTED0125/0127; Q46 closed; no permission name/grant or execution delivered.**
This completes the [final owner matrix](phase-4-r6-customer-decisions.md), reusing accepted 0100,
0070/0085 and 0122–0124. The owner-approved directions now govern; [domain](../domain/sales-customer-decisions.md) and
[slices](phase-4-r6-implementation-slices.md) are specified. This is not legal adjudication,
implementation DDL or authorisation to add acceptance/portal/handover code.

## C5 — Factual evidence and appropriate business practice

Separate the **customer's agreement**, the **employee's authority to record it**, the **seller's
required price/risk approvals**, and **handover readiness**. None proves another. Record customer
Party/representative, exact issue/selection/quantity/basis/amount/terms, evidence source/reference,
actual occurrence and recording time, recorder actor/company, verification basis and any ambiguity.
Only approved commercial prerequisites and verified consent create authoritative accepted claims;
received messages/intake can remain pending. CustomerAccount, e-signature and two-person review
are not universally required. Company evidence requirements may vary with declared risk/contract.

| Evidence channel | What supports a usable decision | What it does not prove alone |
|---|---|---|
|Customer purchase order |Authentic Party/issuer/reference and line/release/version; matching products, quantity, quote/terms and approved differences |A PO with changed price/conditions isn't acceptance of our unchanged quote. Blanket ceiling100 may authorise releases, not firm purchase100. |
|Signed quotation |Identified revision/selection/amount plus signature source, representative and verification evidence |Seller-generated signature box, unrelated signature or unexplained changed terms aren't customer consent. |
|Email confirmation |Original message/source/thread/reference, attributable customer representative and clear agreement to exact terms |Sending quote, delivery/read receipt, forwarded attachment or employee's own draft email isn't agreement. |
|Portal confirmation |Authenticated Party/representative context, exact presented immutable terms, explicit action/time and server evidence |Employee “accept” button or authentication without authority to act for this customer isn't the customer's action. Portal implementation is R8. |
|Verbal instruction |Authorised recorder/witness, identifiable customer representative, when/how conversation occurred and exact agreed scope/price/conditions, with policy-supported attestation |Employee recollection with uncertain price, representative authority or scope is pending evidence, not a reason to fill guesses. Call recording isn't universally mandatory. |
|Other legitimate business evidence |Supported company policy for letter, customer order system, witnessed instruction or other documented practice; preserve original/source and verification |No arbitrary universal external-reference format or electronic-signature mandate. Required jurisdiction/contract formalities must be configured, not invented here. |

Evidence policy defines operational recording requirements, not a universal legal conclusion about
contract enforceability. Customer representative authority needs a recorded verification basis under
company/customer policy; a contact record alone is not unlimited purchasing authority. Conflicting
customer conditions require clarification/reoffer, not silent rejection of their clauses by ERP.
Attachments/versions/verification events are append-only with provenance; file hashes help integrity
and duplicate detection, not authority or proof that two identical documents represent one sale.

### Time and confidence

Store occurrence separately from server ERP recording time. Preserve source timestamp, source time
zone, claimed precision/uncertainty and verification. A remembered date/interval is not fabricated
midnight, exact UTC or the employee's current date. A future/impossible or pre-issue claim is not
ordinary acceptance of that later quote. If evidence cannot establish applicable historical window,
keep pending; don't choose a convenient timestamp to evade expiry/withdrawal/replacement.

Ordinary recording evaluates current offer/claim eligibility at a server decision instant after
locking/re-reading and final prerequisite checks. It does not use a supplied historical timestamp
to bypass current ineligibility. Quote valid-through uses R5's captured timezone/exclusive boundary.
A transaction linearised before the boundary can commit later; that retained instant must be genuine,
not captured early to conceal a long wait. If eligibility instant is after expiry, route late evidence.
A background expiry job is not a prerequisite for correct refusal.

## Governed late-evidence reconciliation recommendation

1. **Capture:** receive original evidence, source/claimed occurrence time and precision, exact old
   issue/terms/selection, recorder and reason for delay. State PENDING_EVIDENCE or DISPUTED; no accepted
   quantity, exclusive claim, target or recognised sale. An old email may belong to an already recorded
   decision case rather than another purchase. Unverified intake alone doesn't reserve scope/freeze
   unrelated sales; any restrictive review hold needs explicit narrow policy/authority, not a hidden claim.
2. **Verify historical facts:** designated authorised reviewer establishes customer consent/authority
   and eligibility under actual issue/validity/disposition/condition evidence at occurrence. Original
   FULL_ONLY100 terms cannot become a historical entitlement to40 just by backdating. Use original
   price/tax/FX/source/approval facts; missing historical facts are not rebuilt from today's masters.
   R8 delivery/withdrawal evidence may matter; an internal seller event alone does not decide legal effect.
3. **Check current conflict:** inspect canonical decision identity, accepted/remaining/option/exclusive
   claims, successor offers and R7 target/correction impact under shared coordination. Earlier claimed
   date is not automatic priority over recorded later business. Customer intent and overlapping scope
   must be resolved; even totals below100 do not prove two records are distinct purchases.
4. **Outcome:** supported verified earlier consent with no incompatible claim/target may be recognised
   through the dedicated governed operation, recording **now** plus proven occurrence time and all
   review/authority/evidence links. Do not reopen the old offer. Any successor eligibility affected by
   root-cap consumption is explicitly blocked/reconciled; nominal issued quantities stay immutable.
   If later accepted/operational claims conflict, stop authoritative recognition until a separately
   authorised commercial/R7 correction plan is approved and applied. Unsupported/disputed evidence
   stays pending or closes as an unresolved/unsupported finding, not invented customer rejection.

Recommend designing this path now but deferring authoritative late-recognition execution until its
sensitive duty, evidential rules, claim effects and R7 conflict checks are accepted/delivered. Normal
acceptance/prepare/Issue authority must not silently receive it. R9 selects vocabulary/grants and
required approval policy using the shared engine; no universal maker/checker or ordinary-manager grant.
Pending evidence is distinct from commercial acceptance, seller withdrawal and accepted cancellation.
Actual consent may have occurred externally; ERP refusal is not a declaration that no legal rights exist.

## C7 — Stable business-decision identity, not just an idempotency key

**Transport identity:** company/actor/operation/key plus canonical request hash returns the original
result on exact retry, after current access. **Business identity:** shared company/Party customer-
decision case and intended scope/consent component, independent of employee, request key, quote
revision, target or attachment filename. Both are needed, with authoritative quantity/exclusive caps.

| Case | Identity recommendation |
|---|---|
|Same customer PO recorded by two employees |Resolve same Party/issuer/reference to shared case before committing. Employees/keys differ but case/consented allocation is the same. |
|PO amendment/revised quote/new email version |Append evidence/version to existing purchase case. Changed accepted terms require correction; a new version does not re-purchase original40. An explicitly additional independently consented line/release needs its own linked decision component. |
|Blanket order/reference reused for releases |Blanket reference plus genuine release/customer instruction identifies the decision; a ceiling isn't firm quantity commitment. Don't assume every matching PO number is the same release. |
|Later genuine purchase of same Item/quantity |Distinct verified customer instruction/reference or supported new-intent attestation. Similar quantity/value isn't dedup key; issued-offer and root availability still must support the new purchase. |
|No external customer reference |Central intake allocates a shared decision case, checks possible prior cases and requires “same decision” versus supported “new purchase” verification. A random new UUID is not proof of new business. |
|Two independently created unreferenced cases may be same verbal event |Flag and keep authoritative recording pending until shared intake/duplicate reconciliation establishes identity. Do not promise perfect automatic identity from amounts/text/time. Distinct instructions can proceed once verified, without universal second-person approval. |

Normalisation retains raw reference/source and declared namespace/issuer; don't merge different
Parties, independently numbered releases or document periods by text similarity. Genuine reference
reuse must be evidenced, not guessed from ERP receipt year. One actual firm PO for100 is one100
commitment even if staff plan delivery40/60. A revised PO total60 after accepted40 is not a new60
purchase by default: any additional20/changed terms need explicit intent and eligible reoffer/claims.

No database constraint can prove two unrelated-looking verbal reports are the same real-world event.
The required safeguard is shared case intake and unresolved-duplicate review **before** authoritative
acceptance, rather than pretending request-key uniqueness solves semantic identity. Additional false
statements by a permitted actor remain an evidence/governance issue, not something amount equality
can decide. Do not let a fresh case/reference bypass exhausted offer quantity or exclusive claims.

## What is claimed atomically

Proposed authoritative recording appends exact customer-decision/evidence and agreed amounts while
claiming: current revision/selection capacity; original logical-scope/overlap-anchor capacity across
reoffers; selected option; exclusive family group where present; entitled original benefit/component
consumption where applicable; receipt and immutable allocation/result references. None is independently
committed first. New agreed assessment/price evidence is distinct from old historical attribution.

R5's coordinator covers all relevant acceptance/replacement/withdrawal/group-membership writers;
claims exist on stable identities even before any acceptance. Lock absence is not a protocol. Re-read
generations/current eligibility after locks, refuse stale requested quantities/options rather than
shrinking or retargeting. Database conservation/uniqueness is required in eventual approved contracts,
not an HTTP button rule. Normal historical receipt replay cannot reopen availability or create a target.

| Race / repeat | Recommended result |
|---|---|
|Same decision case, different keys/actors |Original matching business result under current read/field scope; incompatible terms/quantity are a conflict, not another acceptance. |
|Distinct40 and60 partial decisions against eligible DIVISIBLE100 |Serialize; both may succeed only with genuine distinct consent, supported entitlements/same option and cumulative100. If both merely record one firm100 PO, resolve one case100 instead. |
|Distinct40 and70 against remaining100 |First valid claim commits; second sees insufficient capacity and refuses. No silent70→60 or price/condition change. |
|Two genuinely distinct40 purchases |May total80 when eligible original offer/remaining/root support it. If a40 reoffer is already exhausted, another40 cannot use its old offer just because root100 has60 left; new eligible offer required. |
|Acceptance versus expiry |Eligibility instant after expiry routes to C5, even if request waited in a queue before boundary. Accepted business already recorded is never erased by later expiry. |
|Acceptance versus seller withdrawal |Acceptance first changes availability generation; stale full withdrawal must review remaining only and cannot cancel accepted40. Withdrawal first blocks normal acceptance; proven earlier external consent is C5, not automatic bypass. Initial R5 partial withdrawal support remains gated. |
|Acceptance versus replacement |Replacement first makes predecessor normally ineligible; no retry onto successor. Acceptance first makes replacement's remaining claim stale; initial R5 must refuse partial-family operation until R6/R7 support. |
|Competing family/alternative claims |First eligible accepted family/option binds claim. Other incompatible acceptance refuses with safe conflict; published offers aren't automatically cancelled. |
|Membership/recipient/revision changes versus acceptance |Same coordination and claim history; cannot unlink/regroup/relabel to evade accepted scope or customer consistency. |
|Repeated handover, including fresh keys |R7 business handover intent/consumption returns existing target. Separate supported Order→Job stages or disjoint20/20 splits retain one accepted40; no unconstrained second40. |

## Safety and delayed execution boundaries

All future Actions/Requests require explicit recording/reconciliation duty, independent company/Party/
quote/source eligibility and current effective scope. Sensitive evidence/cost/margin/other-customer
records remain protected. A hidden competitor must not appear as empty claim or leak its identity/
quantity/value through conflicts. Request-specific0070/0085 review does not grant general cost access.
No role-name inference or automatic ordinary editor/manager grant for new sensitive duty.

Ordinary customer recording and sensitive late-recognition/cancellation/claim-release are distinct
accepted duty boundaries, with R9 vocabulary/grants and owner approval still required. Factual intake permissions must not
implicitly grant either approval or authority to assert unresolved consent. Company evidence policy
may support routine verbal decisions; ordinary workflow remains concise when identity/terms are clear.

R7 must specify target business identity, one-vs-many/disjoint consumption, source baseline and amendment/
cancellation ownership before those operations execute. A cancelled Job cannot alone release a customer
or exclusive commitment; original acceptance remains with linked correction. R8 channels/signature/
delivery services and Finance receipts/withholding certificates/statutory postings remain separate.
No portal, acceptance engine, correction workflow or handover is implemented by this research.

## Related

- [Final C1–C8 review](phase-4-r6-customer-decisions.md) · [Worked allocations/journey](phase-4-r6-allocation-and-journey.md)
- [Accepted R5 contract](../domain/quotation-lifecycle.md) · [R5 evidence](phase-4-r5-quotation-lifecycle.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Question workflow](../architecture/implementation-question-reconciliation.md)
