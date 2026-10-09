[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1 — Consolidated decision matrix and owner review

- **Date:** 2026-10-09.
- **Status:** remaining business research consolidated for one owner review; R1/Q39 not closed.
- **Evidence:** [remaining R1 comparison](phase-4-r1-remaining-evidence.md) plus linked existing records.
- **Gate:** documentation only; no new recommendation accepted, no implementation/migrations/R2 research.

## Business meaning, progress and example

R1 settles who the buyer is, who follows up, how genuine deals relate to offers, and how pipeline
progress differs from commitments, readiness and money. This prevents fake CRM records, duplicate
customers, accidental access grants and unreliable pipeline totals.

ADRs 0100–0103 are accepted; the owner-authorised bounded C2 direction is now accepted in ADR 0104.
0098/0099 contain owner-reviewed directions but retain core choices below. Remaining C3/C4/D/E/F/G
research is sufficient for this joint review; it is not yet approved or an R1 completion claim.

Example: ABC asks for furniture and installation; one optional enquiry yields a deal with several
independent offers. A colleague owns follow-up without gaining Finance authority. Furniture is
accepted while account setup is pending; installation remains potential. A later change of owner,
CRM association or pipeline outcome must not rewrite the issued offer, acceptance or forecast history.

## Consolidated matrix

Status key: **A** accepted contract; **R** owner-reviewed direction, formal acceptance pending;
**P** new proposal needing approval; **D** safely deferred detail, no decision implied.

| Topic | What exists | Recommended decision | Why | Trade-off | Status |
|---|---|---|---|---|---|
| A — hybrid entry | No Sales workflow; reviewed 0098 | Separate optional Leads/Opportunities; FLEXIBLE default, MANAGED issue gate/narrow reasoned bypass, STRICT creation/issue gate | Avoid fake CRM; preserve controlled issue | Presets less flexible than arbitrary rules | R — 0098; U1 boundary choices pending |
| B — direct relationships | No Sales association model; reviewed 0099 | Draft corrections; immutable issue linkage plus governed current CRM association | Correct organisation without repricing history | Separate evidence/relationship duties | R — 0099; U1/U2 pending |
| B3 — offers and forecasts | No runtime forecasts | Independent scopes/exclusive groups; MAINTAINED/SELECTED_OFFERS; persistent provisional review, explicit dated SPOT | Prevent competing/revision/accepted double-counting | Operator review/history overhead | A — 0100–0102; detailed R2/R4/R5/R6/R7/R9 gates retained |
| C1 — prospect issue | Party/account split; missing contact/site surfaces | Eligible Party-backed official issue, no account solely for issue; context-dependent recipient evidence | Avoid premature financial onboarding | New recipient-readiness/snapshot integration | A — 0103; physical schema R2 |
| C2 — acceptance/readiness | Accounts/permissions, no commercial/payment/job workflows | Record valid acceptance before account; preparation allowed; recheck operational/financial authority at actual operations | Preserve facts without granting exposure | Visible readiness blockers | A — 0104; no universal account-at-handover rule |
| C3/D — persons, contacts, sites | Organisation-oriented Party with no kind; child models | One Party identity supporting organisation and individual contracting customers; Company default exposure, person support in initial design | Generic industries/B2C without separate masters | Person validation/privacy and existing-row classification needed | P — U3; field/schema details R2/C3/R4/R9 |
| C4/G — matching, restore, conversion | Code/account uniqueness; soft-delete archive; no merge/restore UI | Warn/reuse candidates, controlled restore, auditable explicit qualification/linking; no automatic identity/account creation or merge | Protect identity continuity and Finance duties | Human resolution of ambiguous matches | P — U3; restore mechanics R9, physical invariants R2 |
| E — responsibility | Company membership/suspension; no Sales owner | One eligible active member per opportunity; lead queue can be unassigned; audited reassignment | Clear follow-up without abandoned records | Management reassignment duty | P — U4 |
| E — visibility | Capabilities/fields/MY_UNITS infrastructure; warehouse scope only | Responsibility separate from rights; company-wide grants or deliberately limited own/assigned/unit coverage; management via explicit rights | Small-company simplicity and larger-company confidentiality | R9 must integrate each supported scope; teams not assumed | P — U4; exact scope API/vocabulary D — R9 |
| F — stages/outcomes | No Sales stages/outcomes | Configurable open stages, stable OPEN/WON/LOST/ABANDONED meaning; partial win derived separately; governed manual closure/reopen | Explain progress without mutating commitments | Must explain remaining scope on closure | P — U5; detailed corrections R5/R6/R7/R9 |
| G — minimum CRM | No CRM runtime; common UI/audit foundations | Optional intake/qualification, owner, notes, next action/date, stage, outcomes/reopen, identity lookup/link and scoped pipeline | Usable follow-up with low entry burden | Advanced automation deferred | P — U6 |
| Later packages | Existing independent pricing/currency/approval design | Defer line/revision/partial scope, precise FX, handover gates, communication and analytics mechanics | Keeps R1 bounded | Delivery waits for its own dependencies | D — R2–R9/Phases 5–6 |

## ADR 0098/0099 blockers — business versus implementation

These proposals must not remain open merely for lock/table/API choices. U1/U2 are the genuine
business blockers; other rows have bounded reconciliation or explicit later gates.

| Open wording | Business consequence / options | Recommendation | Blocks R1? / owner |
|---|---|---|---|
| STRICT historical issued import (0098) | Refuse/match genuine live links; separate controlled archive import; or weaken policy/fake deals | Keep normal paths STRICT; initial legacy offers read-only with provenance, no implicit live acceptance/revision. Governed live historical import only after explicit R5/R9 design | Yes: U1 must approve initial boundary; detailed import mechanics R9 |
| STRICT draft unlink (0098/0099) | Temporary missing link blocks issue but departs from continuous strict link; atomic replacement prevents it | Under STRICT require atomic link replacement, no ordinary unlink. Existing unlinked drafts after policy transition may be repaired but not issued; ordinary FLEXIBLE/MANAGED corrections stay | Yes: U1; correction/revision mechanics R5 |
| Policy governance/version (0098) | Uncontrolled changes could evade managed oversight; freeze all drafts burdens transitions | Dedicated company Sales-policy duty, reason/actor/time/effective version; effective policy at operation; preserve issued evidence; no role names/team overrides now | Existing governance direction reviewed; endorse U1 package, exact capability/store R9; not a standalone blocker |
| Issue/revocation/replay (0098) | Stale authority can issue/bypass; retries can fabricate success | Original write checks live authority; prior revocation observed; failed attempt no success. Identical completed retry uses original evidence plus current required access/authority per domain-integrity | No new choice; existing contract. Locks/linearisation/shared auth protocol R5/R9, not an R1 hold |
| Unknown prospect/Party (0099) | Names/unbound enquiries could attach different customers | Opportunity may start unbound; before issue association resolves same eligible Party. Explicit authorised first binding; never silently overwrite known identity or create account | C1/C2 settled foundation; approve U2 binding boundary. Exact quote recipient/schema R2 |
| Association unit and closed state (0099) | Different revisions associated to different deals confuse offer responsibility; closed deals could collect new active work | Current CRM association organises the offer family consistently; issue-time links per revision stay immutable. Normal target is OPEN; reopen first rather than attach new pursuit to closed target | Yes: U2 business responsibility/eligibility; physical family/projection R2/R5 |
| Accepted/job-linked moves and closed-source repair (0099) | CRM repair could move commercial/job ownership or exclusive group | Preserve all commitments/groups/jobs; allow reasoned removal/reassignment from closed source to eligible open target only for otherwise ordinary issued offers. Accepted/job-linked exceptions remain unsupported until governed rules | U2 approves initial supported boundary; exceptional operations R5/R6/R7/R9, not full R1 blocker |
| Visibility/history (0099) | Old/new restricted relationships or financial fields leak through history | Guard both sides/current scope, redacted governed history; no record grant from reassignment; managers use explicit scope/capabilities | U4 settles responsibility/access contract; exact projection/aggregate privacy R9 |

## Owner decisions — six related bundles, review together

Only the following choices need approval. The recommendations are proposals; alternatives remain
available and individual choices within a bundle may be answered differently.

### U1 — STRICT corrections and legacy scope

**Problem/why:** corrections and old data must not become ways around a strict live workflow.
**Example:** QT-10 needs a different opportunity; imported QT-1999 has no original deal.
**Options/consequences:** temporary unlink eases corrections but permits a missing strict relation;
atomic replacement retains it. Live historical import adds operational eligibility questions;
read-only legacy archive preserves evidence with a smaller initial scope. Policy weakening/fake
opportunities corrupt evidence and are rejected alternatives.
**Recommend:** atomic STRICT replacement; archive-only legacy scope initially; separate authorised
Sales policy changes with reason/version. **Technical:** preserves 0098 presets and domain-Action
import boundary; later live migration purpose requires R5/R9 decisions. No grandfathered new issue.

### U2 — Consistent offer responsibility and opportunity eligibility

**Problem/why:** CRM organisation must be correct without becoming a commercial amendment.
**Example:** several revisions of the same furniture offer belong to one current deal; an old
closed deal was linked incorrectly. **Options/consequences:** revision-level current CRM links
allow split responsibility but complicate history/forecasting; family-consistent organisation
keeps one offer coherent. Closed-target attachment avoids reopening but mixes new pursuit with
closed outcomes. **Recommend:** family-consistent current association; same eligible Party before
issue, explicit binding when previously unknown; normal OPEN targets, reopen first. Permit reasoned
repair from closed source without reopening it; accepted/job-linked exceptional moves unsupported
until later decisions. **Technical:** separate per-revision frozen issue evidence; do not move
groups, accepted scope or job ownership; R2 selects the physical representation.

### U3 — Generic identity, controlled reuse and conversion

**Problem/why:** an individual buyer is not merely an employee contact, and a returning supplier
must not become a duplicate customer. **Example:** Fatima buys personally; ABC's procurement
contact buys for ABC; an archived ABC supplier returns as a buyer.
**Options/consequences:** organisation-only first is smaller but delays B2C; generic organisation/
individual Party supports both with extra validation/privacy. Auto-merge is fast but may combine
different entities; create-fresh bypasses archive controls; human candidate review preserves identity.
**Recommend:** support both contracting kinds through one Party; company-oriented default UI and
contextual person controls. Search/candidate warnings before create, explicit link/reuse, governed
restore of the same identity with roles restored independently. No automatic merge, master-field
overwrite or CustomerAccount on lead qualification. An enquiry may create several real deals
through explicit repeatable actions with provenance; reject duplicate retry, not legitimate second
deal. **Technical:** retain same-company/account uniqueness; contacts/sites remain owned children,
not new financial identities. A company contact who buys personally needs an explicitly resolved
contracting Party, not conversion of the employer identity. Duplicate candidates and archived
search are permission/scoped; restricted matches require authorised resolution, not identity
leakage or automatic merge. Existing tax/registration identifiers remain warning evidence unless
a later jurisdiction-specific decision justifies a hard constraint. Data conversion/backfill/privacy
validation are later design gates.

### U4 — Ownership, visibility and inactive users

**Problem/why:** assigning follow-up should not expose other deals or financial data.
**Example:** Aisha leaves; Bilal takes the opportunity while Finance keeps mapping authority.
**Options/consequences:** company-wide capability grants are simple but broad; owner-only limits
privacy but may hide queues/cover; selling teams add management complexity. Automatic rights from
ownership undermine existing authorisation. **Recommend:** one eligible active owner per opportunity,
unassigned Lead queue allowed; audited reassignment with history/reason. Retain suspended owner's
history, flag responsibility needing reassignment, permit scoped authorised management recovery;
no silent owner deletion or automatic grant. Use existing additive capability/scope foundations:
company grants default, deliberately limited own/explicit-assigned/unit access where integrated;
management coverage explicit. Ownership transfer must validate destination authority/eligibility,
not create it. **Technical:** R9 implements supported Sales scopes before restricted use; fail
closed for unsupported scope, exports/lookups/counts/history included. Team rosters/routing may
follow using organisation units, not a second company boundary. This is not acceptance of new
scope enums/permission vocabulary or a claim they exist now.

### U5 — Stages, partial wins, closure and reopening

**Problem/why:** one accepted scope must not hide remaining demand or fabricate a full win.
**Example:** furniture accepted, installation undecided; later buyer declines installation.
**Options/consequences:** automatic WON at first acceptance is easy but loses remaining scope;
PARTIALLY_WON terminal blocks continuing pursuit; separate progress/outcome preserves both.
Manual WON without evidence supports off-system sales but can invent commitments.
**Recommend:** configurable ordered open stages independent of stable outcomes. Partial win is a
separate derived indication while OPEN. Authorised manual closure records actor/time/reason and
remaining-scope disposition: WON requires authoritative accepted scope and no further pursuit;
LOST means unsuccessful buyer/deal outcome, ABANDONED means pursuit discontinued. Positive active
accepted scope prevents a whole-deal LOST/ABANDONED classification; partial loss is remainder
context, not erasure. Initially require recorded quotation acceptance for WON; unquoted/off-system
wins need separate authoritative acceptance support later, not free-entry accepted money.
Reopen same revived pursuit with reason/history, retain accepted scopes and prior closures; new
buying event gets a new opportunity. Closure/reopening cannot withdraw/revive quotations, cancel
jobs, release exclusive groups or restore forecast trust. Closed pursuit has no active remaining
forecast contribution; record disposition separately from unchanged publication history.
**Technical:** business stage governance (authorised edits, retire used stages rather than lose
history) now; partial corrections/cancellation evidence R6, quote coexistence/expiry R5, exact
forecast review/retirement/report history R9. No automatic quote cascade or forced all-quotes-closed rule.

### U6 — Initial CRM and progressive exposure

**Problem/why:** follow-up needs useful basics without enterprise setup overhead.
**Example:** a small service firm tracks a callback; a larger team wants assignments and pipeline.
**Options/consequences:** quotation-only CRM omits follow-up; full automation adds expensive
configuration; a small common foundation scales by exposure. **Recommend:** optional Lead intake,
explicit qualification/disqualification and reopen history, Opportunity create/link, owner, contact/
Party lookup, description/source, notes with author/time, next action/date, optional expected close,
stage/outcome reasons, simple scoped lists/pipeline and already accepted forecast methods. Unknown
values remain unknown. Direct quotation users need no CRM warnings in FLEXIBLE. Lead qualification
may produce several deals without consuming/deleting the enquiry; disqualification reasons and
reactivation preserve history. No automatic reminders or close-on-age. **Technical:** company
configuration/UI exposure separate from domain capability; defer campaigns/scoring/territories,
commissions, email ingestion/sync, activity plans/calendars, shared selling teams, predictive scoring,
master merge, notification delivery (Q33) and portals until justified. Normal record linking/reuse,
notes and human follow-up are part of usable initial CRM; no promise of a separate activity engine.

## Minimum cross-topic invariants and closure path

Identity, scope and current authority apply to every entry path; owner/CRM/stage cannot grant
Finance rights. Immutable issue, accepted scope and publication evidence survive all changes.
Optional CRM cannot create fake records or bypass pricing/approval/recipient controls. Accepted
business differs from outcome and from work/credit authority. Closed or reopened pursuit cannot
silently manipulate live quote commitments, groups, jobs or forecast trust. Details gate dependent
operations; unsupported advanced cases stay unsupported, not silently inferred.

After owner review: reconcile 0098/0099 with U1/U2/U4 and mark accepted only if approved semantics
are unambiguous; retain their R2/R5/R6/R7/R9 delivery gates. Record approved identity/conversion,
ownership/access and outcome/minimum-CRM contracts in new sequential ADRs using the index's then-
free numbers (do not reserve numbers now). Update domain/research indices, phase task and Q39;
close whole R1/remove Q39 only when U1–U6 and all genuine foundation choices are resolved or
explicitly excluded from initial scope. No remaining physical table/lock design alone blocks R1.

**Next recommended package after that review/closure: R2 — quotation structure and scope.**
This record does not begin it. R3–R9 detailed questions and Phase 5/6 duties retain their owners.

## Related

- [Remaining evidence](phase-4-r1-remaining-evidence.md) · [R1](phase-4-r1-leads-and-relationships.md)
- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
- [ADR 0098](../decisions/0098-hybrid-sales-opportunity-requirement.md) · [ADR 0099](../decisions/0099-separate-quotation-issue-and-crm-association.md)
- [ADR 0103](../decisions/0103-issue-quotations-to-party-backed-prospects.md) · [ADR 0104](../decisions/0104-separate-customer-acceptance-and-readiness.md)
