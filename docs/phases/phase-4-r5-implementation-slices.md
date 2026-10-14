[← Phase index](README.md)

# R5 — Quotation lifecycle implementation handoff

**2026-10-13 · ACCEPTED L1–L6 / SPECIFIED slices / code NOT AUTHORISED.**
ADRs [0122](../decisions/0122-govern-quotation-candidates-adoption-and-revalidation.md),
[0123](../decisions/0123-issue-numbered-quotation-revisions-atomically.md),
[0124](../decisions/0124-preserve-quotation-availability-and-scope-replacements.md) close planning Q45.
[Lifecycle contract](../domain/quotation-lifecycle.md) supplies typed persistence, Actions/Requests,
lock order, API/UI and constraints. [R4](phase-4-r4-implementation-slices.md)/[R3](phase-4-r3-implementation-slices.md)
adapters remain undelivered; accepted/specification is not implementation or verification.
Owner coordinates any coding handoff. CRM/C2 code, delivery documents, seeders/tests/generated files
stay implementation-owned; C1d has independent authority gate. No application checks in this task.

## Required authorisation contract — R9 before public enablement

These **candidate permission names** express narrow duties, not new registered/granted authority.
R9 must reconcile exact vocabulary, company/record/read/sensitive scope, limits and grant continuity
before a public surface ships. Reuse existing permissions when genuinely suitable; register new duties
with explicit designated owners and grants, not automatically ordinary editors/salespeople/managers.
Both Request and Action enforce duty plus independent quote/Party/source eligibility. Ownership grants
no access. Unsupported limited Sales scope refuses under 0106 until legitimate scoped support exists.

| Candidate duty | Operations / boundary |
|---|---|
| `sales.quotation.prepare` | Draft create/edit, source-appropriate preview, candidate/freshness review, normal revision drafting, draft cancellation/reason and ordinary review submission. Not issue, withdrawal or approval. |
| `sales.quotation.issue` | Atomic official issue after all independent controls; routine path only. Not exceptional discount/risk/contract authority or customer consent. |
| `sales.quotation.withdraw` | Governed seller removal of eligible unaccepted availability; initial whole-only. Replacement additionally needs this duty where it performs this withdrawal function; Issue alone is not blanket withdrawal. |
| `sales.quotation.approve` | Shared ordinary quote-review eligibility with configured authority/delegation/steps; request-specific0085 read. Not general cost read or exceptional control bypass. |
| `sales.quotation.policy.manage` | Versioned company validity-default/UNKNOWN payment policy. Not exemption, finance approval or arbitrary quote editing. |
| R3 exact discount/override/term/config duties | Reuse their approved/delivered R9 vocabulary. Neither prepare/issue nor general quote approval automatically grants them. |
| Existing `sales.numbering.manage` / `numbering.series.select` | Existing framework configuration / explicit manual series choice respectively. No second Sales numbering UI/counter. |
| Source material/cost duties | Reuse genuinely suitable existing source governance; independent applicable sensitive reads. Approval of quote total cannot clear blocked material adoption. |

No initial risk-bypass, contractual departure, customer acceptance, accepted-scope amendment or
multi-line document-discount permission/endpoint. Genuine full-access re-sync only under existing
explicit machine-verifiable mandate across capabilities; do not infer from role names. Sensitive
new duties require owner approval; role assignment/real holders is not a demo seeder side effect.

## QL0 — Aggregate, logical identities and integrity foundation

**Depends:** 0109/0110/0115–0120 and 0122–0124; explicit coding authorisation. Public duty gates still apply later.
**Models/Actions:** family/revision/logical scope/choice group identities, draft state/generation,
immutable candidate/issue/event/receipt models and Sales mutation coordinator; source/acceptance-state
ports explicitly supported or refused. Register monetary FinancialActivity and numbering type via0079.
**API/UI:** internal foundation only; no unguarded prototype quotation routes or orphan fake records.
**Migration:** additive composite FKs/uniques/indexes/state/money/known-nullability/immutable evidence/
issue completeness/availability-overlap constraints, real down. No acceptance history invented from
notes. New R5-born families may have explicit NONE_WITH_WRITERS_DISABLED state only when authoritative
acceptance/import writers are demonstrably disabled; imported/enabled/unknown state needs real adapter.
**Demo:** future deterministic fixtures for logical intent versus repeated Item and family alternatives;
no changes to implementation agent's module seeders before separate coding approval.
**Acceptance:** raw-SQL cross-company links, duplicate family ordinals/issues/numbers, issued snapshot
mutation, unbacked available projections and alternate-writer bypass rejected. Same Item twice not
silently merged; choice baseline counted once, explanatory child uncharged. FinancialActivity includes
money-bearing draft/candidate/issue rows and races safely with company-base change. Source/FX insert
and update fencing tests prove no phantom stale eligibility; missing required port refuses.

## QL1 — Editable drafts, source preview and complete preparation UI

**Depends:** QL0 + PR0/PR1/PR4 and supported FR0–FR4; R9 duties/grants. Complex managed fields require
PR2/PR3; advanced operands require their delivered adapters, not merely schema support.
**Actions/Requests:** Create/UpdateQuotationDraft, PreviewQuotation, typed source fields and input
version;0098 creation/link policy,0099 draft association history. Save never adopts/approves preview.
**API/UI:** `/quotations`, scoped detail/list/draft PATCH, preview; one shared form with Party/prospect,
source, measures/package, optional flat sections/choices, explicit pricing/tax dates/currency/valid-through,
R3 bounded discount/replacement confirmation and protected result. Missing Party can remain incomplete
DRAFT; preview/readiness refuses missing required identity. Saving entered money still requires valid
0012/R4 dual-money evidence; unsupported/missing FX cannot create incomplete monetary rows.
**Coverage:** fields appear on appropriate form/detail/card/list/summary/history; filters include
supported dates, draft/issued and derived availability; server pagination/scoped counts. Keyboard,
phone/light/dark/loading/empty/validation/denied/conflict/stale/no-overflow follow shared standards.
**Demo:** future SIMPLE maintained stocked goods, service hours, manual line, optional Assembly;
Party without CustomerAccount; protected cost-blind user and limited-scope denial.
**Acceptance:** save inputs while output-tax/cost prerequisites remain unresolved where money inputs
are valid; no cached PASS/adoption, invented master/account or JS money floats. Draft PATCH never
changes issued/cancelled rows. Current source tenancy, units/representability and Party/Opportunity
consistency checked in Request+direct Action. Relevant edit invalidates old candidate without deleting
history. Client fake approved/base FX/cost/status fields refused; previews are consistent, not authority.
Opaque review tokens cannot disclose cost/threshold hashes or grant authority; tampered/expired context
refuses new issue, while matching completed receipt replays before new token/active-state validation.

## QL2 — Immutable candidates, source adoption and shared review

**Depends:** QL1 + existing0070/0085 and source control adapters0054/0058/0072/0076; R9 submission,
approval/request-specific/sensitive duty contract. No universal maker/checker.
**Actions/Requests:** FreezeQuotationCandidate, SubmitQuotationReview, RevalidateQuotationCandidate,
AdoptQuotationEvidence. Register Sales ApprovalSubject/ReviewProvider; candidates and approvals cite
exact original source/financial versions. Required pre-adoption source approval cannot be postponed.
**API/UI:** candidate/review/revalidation/adoption endpoints and scoped history; existing shared
approval workspace receives safe whitelisted operational/commercial/technical blocks. Detail clearly
labels draft, preview, pending reviewed proposal, adopted evidence and valid/invalid review.
**Demo:** routine no-approval quote; material REASON_REQUIRED / APPROVAL_REQUIRED / BLOCKED;
quote financial review under configured policy and an independent technical duty example.
**Acceptance:** equal money but changed Party/qty/cost certainty/plan/discount/rule/agreement/FX/tax/
options/routing invalidates; immutable candidate replaced, not edited. Genuine both-hash no-change
keeps usable approval and appends observation receipt. No expired/rejected/invalidated resurrection.
Pure irrelevant note may preserve; user-facing scope change cannot. NONE doesn't bypass source/exception
requirement; REQUIRED with no matching policy refuses. Direct/manual has no fabricated material approval.
Delegation/authority/read projections and missing mandatory evidence follow existing engine; no cost leak
through inbox/history/errors. Concurrent edit/submission/adoption uses coordinator and expected versions.

## QL3 — Atomic official issue, numbering and unknown-payment policy

**Depends:** QL0–QL2, full supported PR/FR financial/source controls, R9 exact issue/grants;0123/0079.
**Actions/Requests:** IssueQuotation; internal routine candidate/adoption fast path; policy-version
Action/Request, exact UNKNOWN acknowledgement. Known valid-through plus frozen zone/expiry required.
**API/UI:** named issue POST, current allowed actions, review-before-issue; only relevant acknowledgement/
source controls shown. Policy config sheet defaults BLOCK, optional governed date default (no universal
age/duration); existing numbering setup type link, one official number per issued revision.
Issue history/resource preserves scoped immutable financial/recipient/choice/approval/cost/FX facts.
**Demo:** SIMPLE10×100 net1000 + tax100 gross1100; no unnecessary adoption/approval button. UNKNOWN
withholding blocks; explicit opt-in + bound issuer acknowledgement allows otherwise complete offer.
**Acceptance:** transaction failure after snapshot/number/receipt stages leaves no half-issue;
GAPLESS returns uncommitted counter, ALLOW_GAPS may leave gap. Same-key concurrent issue/timeout replay
returns original number/time/evidence; different payload conflicts, different-key same revision cannot
allocate twice. Current authority/read/projection checked before replay. Date/expiry/profile/rate/plan/
cost races refuse changed reviewed facts, not silently reprice. Future/expired agreement cannot be
made eligible by backdating. All mandatory unknown/failures block; UNKNOWN ack cannot manufacture
cost, tax, FX, exemption or approval. Unsupported tax graph refuses; corrected MoneyCast adapter must
be verified in authorised FR0, not assumed. R8 PDF failure doesn't undo issue or allocate new number.

## QL4 — Validity, cancellation, withdrawal and honest disposition UI

**Depends:** QL3,0124, R9 governed withdrawal/read duties, authoritative supported acceptance-state
reader/writer exclusion. Customer rejection and accepted-business cancellation remain R6/R7 operations.
**Actions/Requests:** CancelQuotationDraft, WithdrawQuotationOffer; availability read evaluates stored
expiry as-of. Issue snapshot and original expiry never edited; reasoned seller events append atomically.
**API/UI:** cancellation/withdrawal named POSTs; list/detail distinguish seller withdrawal, expiry,
replacement, future customer rejection and future accepted-business correction. No generic “Cancel quote”
button implying accepted Order/Job cancellation. Actionable availability derives from current scope/
expiry/permissions, not stored header status alone; customers' actual decisions aren't inferred.
**Demo:** cancelled successor draft with original still available; valid-through today versus next local
day; whole withdrawn unaccepted issue, independent competing families unchanged.
**Acceptance:** captured timezone boundary survives later company timezone change, local/UTC midnight
cases correct; no scheduler required to block expired issue/acceptance availability. Same-key reasoned
withdrawal replay no second event; stale generation conflicts. Initial partial/unknown acceptance state
refuses rather than zero. No automatic lost/won Opportunity or trusted forecast update;0101/0102
remaining-source changes stay provisional and R9 integration remains required. No historic validity PATCH.

## QL5 — Draft revisions and whole-unaccepted replacement

**Depends:** QL0–QL4 + authoritative claim state,0124, R9 issue/withdraw/revise scope. R6/R7 required
before any partial replacement; approval alone without delivered adapters does not remove refusal.
**Actions/Requests:** CreateQuotationRevision and IssueQuotationReplacement; explicit predecessor,
logical scope and expected availability generation/reason; same transaction with new number/issue.
**API/UI:** family history with original and each official number, copy/input provenance, predecessor
comparison and replacement confirmation. Draft2 leaves1 available; cancelled2 does not withdraw1.
Only full eligible unaccepted replacement initially; no partial acceptance, consent or commitment button.
**Demo:** first issued100, draft successor and cancelled draft; whole revision issue with before/after
price/validity and old issue retained; exclusive competing **families** still permitted by 0100.
**Acceptance:** no duplicate scope from Item repetition/copy; no carried approval, no blind live price
or benefit duplication. Whole replacement freezes new issue and removes only explicit old availability;
failed transaction preserves original availability, successful retry does nothing twice. Concurrent
withdrawal/replacement/issue serialise, stale predecessor refuses; no new command silently targets a
newer revision. Partial claim or missing R6/R7 consent/allocation/commitment support fails at Action
and DB/writer contract. Accepted terms/Order/Job remain untouched; no automatic release of exclusive claim.

## QL6 — Capability transitions, integration guards and completion boundary

**Depends:** QL0–QL5, relevant PR6/FR6 if enabled, R9 mode/config duty. No approval for enabling every
advanced method/formula. Initial unsupported modes/paths refuse honestly.
**Actions/Requests:** R3 pricing-mode transition uses R5 coordinator and impact fingerprint; candidate/
pending adoption/current config dependencies reconcile or refuse. Source/config writers join the
published compatible fence/generation protocol before activation. Scope/claim ports expose future
R6/R7 interfaces with explicit UNSUPPORTED status, not fake acceptance/job data.
**API/UI:** existing mode/settings surfaces explain blocked transition/dependencies; issued explanation
remains readable. History and safe UNKNOWN customer projection contracts exist now; PDF/send is R8.
**Demo/acceptance:** advanced dependency blocks downgrade; resolved authorised transition preserves
historical pricing/tax/component evidence. No hidden effect or authenticated-only import/console path.
Repeated toggle cannot erase review requirement. Evidence getters/replay/history with current scope
and field permissions; DB rejects unauthorised writer shortcuts into issued facts/availability.

**Complete initial R5 delivery** means QL0–QL6's supported kernel/forms/review/issue/cancel/expiry/
whole-withdrawal/whole-replacement/mode-refusal/history coverage, verified under separate coding
approval and final R9 controls. It does NOT mean customer rejection/acceptance, partial replace,
late decision correction, commitment creation/amendment, new header discount, general risk exception,
contract departure, advanced tax engine, PDF/send, Finance or forecast/partial-win metrics delivered.
R6/R7 gates are not missing happy paths to fill silently. Enabling acceptance later requires upgrading
all shared availability/replacement/withdrawal readers/writers and database conservation together.

## Common checks and coordination

Each authorised coding slice supplies matched Action/Request/Resource guards, scoped lookup/list/
counts/nested/history/replay tests, registry/grant rollout, direct Action denials, stable concurrency/
idempotency checks, immutable/raw SQL constraints, OpenAPI/client regeneration, meaningful module
demos, browser/API journeys and all changed-area checks including isolated migration rollback/reapply.
Record each delivery state/evidence; no planning response marks a coding question VERIFIED.
No app tests run here; docs-only links/numbering/whitespace/body-preservation checks apply.
Forwarded questions retain Origin: IMPLEMENTATION AGENT and original context via owner; none supplied.

## Related

- [R7 accepted contract and actual-delivery gates](phase-4-r7-implementation-slices.md) — R7 authority is accepted0130–0132; necessary adapters remain undelivered, so affected partial-family/correction gates remain.

- [Lifecycle domain](../domain/quotation-lifecycle.md) · [Approved L1–L6](phase-4-r5-business-review.md)
- [R6 initial review](phase-4-r6-customer-decisions.md) · [R6/R7 journey](phase-4-r5-acceptance-and-handover-boundaries.md)
- [R5 evidence](phase-4-r5-quotation-lifecycle.md) · [Phase 4](phase-4-sales-quotations-and-handover.md)
- [Pre-push checks](../architecture/pre-push-checks.md) · [Question workflow](../architecture/implementation-question-reconciliation.md)
