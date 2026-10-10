[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# CRM policy reconciliation — three owner decisions

**2026-10-10 · A/B/C BUSINESS DIRECTIONS ACCEPTED by owner review of `a70af72`; NOT IMPLEMENTED.**
Accepted records: [0111](../decisions/0111-disqualify-leads-independently-of-derived-opportunities.md),
[0112](../decisions/0112-correct-historical-opportunity-responsibility-with-append-only-evidence.md),
[0113](../decisions/0113-preserve-original-lead-intake-and-govern-current-detail-corrections.md).
The initial options/research below retain the reasoning; accepted ADRs govern. Current code behaviour
is source-inspected, not evidence of correction delivery. Historical-attribution capability/grants
remain awaiting approval; [implementation specification](phase-4-crm-correction-specification.md)
and [domain model](../domain/crm-corrections.md) supply the handoff. Named administrative assignee
is excluded for now; notes remain the routine closed-record follow-up. Earlier accepted ADRs unchanged.

## A — Disqualify intake independently from valid derived pursuits

**Current:** `Sales/Actions/DecideLead::disqualify` refuses while any linked opportunity is OPEN
(`LEAD_HAS_OPEN_OPPORTUNITY`). Conversion refuses disqualified intake until revived.

**Problem/example:** a duplicate enquiry has already produced a genuine installation deal.
Stopping work on that intake should not require losing the valid deal. A mistaken qualification
may need correction without retrospectively deleting the conversion or denying that it occurred.

| Option | Consequence |
|---|---|
| Retain current coupling | Simple qualification narrative, but valid pursuits must close before intake can stop. |
| Allow independent reasoned disqualification (recommended) | Stops intake while preserving deals; reports must distinguish intake disposition from pursuit outcomes. |
| Add duplicate/void/merged states now | More specific semantics, but unnecessary lifecycle and merge machinery; defer. |

**Accepted direction:** allow authorised disqualification with reason, even with OPEN derived deals;
record the intake decision and preserve all links/conversion/qualification history. Do not change
opportunity outcome, owner, stage, amounts, quotes, commitments, jobs or forecasts. A duplicate
reason is free text, not an automatic merge or deduplication claim. Revive with reason before new
conversion; replay of an already completed conversion remains a retry, not a new pursuit. A genuinely
new buying event normally gets a new enquiry/deal; reuse revival for the same resumed intake.

**Correction boundaries:** change the one coupling guard and corresponding tests/docs in the implementation slice; preserve locking, capability/scope, history and conversion retry guarantees. Define race
expectations: new conversion and disqualification serialize on the lead; conversion winning first
does not prevent subsequent disqualification, disqualification winning first refuses new intent.
Report historical qualification/conversion events independently of today's intake status. Revival
must not recreate converted deals or erase prior reasons. No opportunity-closing cascade.

## B — Separate pursuit responsibility from closed-record administration

**Current:** `ReassignOpportunity` refuses closed records; the database closed-row trigger refuses
owner changes. Reopening changes outcome to OPEN and retains closure history. Notes can already
be appended to closed records through `AddSalesNote` without reopening.

**Problem/example:** Bilal closed a lost deal, then left. Aisha needs to answer an archive query;
that does not revive selling. Separately, a report may wrongly attribute the closure to Bilal when
Aisha actually owned the pursuit. These are distinct facts.

| Operation | Recommendation and consequence |
|---|---|
| Actively pursued reassignment | Retain ordinary owner change for OPEN deals, active eligible new owner and old/new history (ADR 0106). |
| Closed administrative follow-up | Existing authored notes only for now. A named administrative assignee or general closed-record owner editing needs further owner approval. |
| Historical attribution correction | Reasoned append-only correction referencing the mistaken event/period, corrected attribution, actor and recorded time. Preserve original evidence; never backdate or rewrite events/issued snapshots. |

**Options:** reopen for every correction (simple but false pursuit); freely edit owner (ambiguous
history); separate corrections/administration (recommended, adds explicit semantics). All three
meanings are useful, but a separate administrative-owner field is not proven necessary for initial
delivery: notes already satisfy basic closed follow-up. Owner has approved historical correction and explicitly deferred named closed-record assignment.

**Correction boundaries:** historical correction must not itself update outcome/stage/closure
facts, forecast trust or commercial evidence. Ordinary latest owner and corrected historical
attribution require separately labelled report bases. A historical correction may reference an
inactive former member without making them a newly assigned active owner; any separately approved future administrative
assignment would need an active member. Capabilities for sensitive attribution correction must be
settled by duty, not inferred from manager role or ordinary edit. Use append-only Sales evidence and
existing audit; narrowly design any database exception only if current assignment is approved.
Specify expected-version checks, retry identity, atomic evidence and cross-company/scope denial.

## C — Preserve received enquiry and explain corrections

**Current:** `UpdateLead` changes NEW intake fields in place; revival makes the record NEW again.
`LEAD_RECEIVED` details currently retain source/Party/assignee, not the full enquiry. Lead inherits
`App/Models/Model` and `LogsActivityWithDefaults`, but that default calls `logFillable()`;
Lead defines guarded `id` and no fillable list. Installed Spatie `attributesToBeLogged` takes
`getFillable()` for that option. Thus the shared trait alone does not establish field history here.
This corrects the earlier provisional suggestion that shared logging might already preserve edits.

**Problem/example:** submitted phone ends in 123; staff correct it to 132, then after revival correct
the organisation name. Users need usable current details and an explanation of what arrived.

| Option | Consequence |
|---|---|
| Mutable fields plus current generic audit | Least storage; actual default lacks the required field evidence and cannot guarantee original intake. |
| Immutable received evidence plus corrected current projection (recommended) | Clear original/current distinction; reuses Lead and append-only Sales history, not a Party master. |
| Freeze all fields permanently | Preserves evidence but forces corrections into unstructured notes and leaves unusable current data. |

**Accepted direction:** capture the accepted received name/organisation/email/phone/description/source
and received time, recorder/time and available submission provenance once. Preserve corrected current
fields on the same Lead and append field-level old/new values, actor/time and correction reason;
no-op creates no correction. Retain original and correction history through qualification,
disqualification and revival. Permit explicit governed intake correction after decisions; do not
reclassify a lead merely to correct spelling. Master Party/contact changes remain separate operations.
Capture accepted input, not secret/raw request payloads or an unrequested attachment ingestion system.

**Correction boundaries:** reuse `sales_events` for original/correction evidence or demonstrate an
equally durable shared-audit contract; do not globally change audit defaults for unrelated models.
Evidence/projection commit atomically; stale corrections refuse, retries do not duplicate evidence.
Enforce schema/writer preservation, same-company scope and read protection for original values as
well as current values. Existing rows must be labelled baseline-at-cutover unless original evidence
is actually recoverable; never call today's values the historic submission. An additive migration
and rollout may be needed; no historical migration edit/reset is authorised by this proposal.

## Bounded primary comparison — accessed 2026-10-10

| Evidence | Observed distinction / applicability |
|---|---|
| [Dynamics lead qualification](https://learn.microsoft.com/en-us/dynamics365/sales/qualify-lead-convert-opportunity-sales) | Multiple opportunities supported; disqualification requires no linked opportunity. This differs from proposed A and does not validate independent disqualification as universal practice. |
| [ERPNext Lead](https://docs.frappe.io/erpnext/lead) | Lead statuses mix manual and downstream-derived meanings; notes support handover. Our intake/pursuit separation deliberately avoids copying those cascades. |
| [Odoo lost/restore, official 19.0 source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/crm/pipeline/lost_opportunities.rst) | Loss reasons and restoration retain operational context; this does not establish a closed-attribution correction rule. |
| [Dataverse owner changes](https://learn.microsoft.com/en-us/power-platform/admin/update-record-owner) | Ownership, privileges and configured cascades are distinct concerns; disabled-owner support is not our new-active-owner policy. Documentation does not prove closed Sales correction eligibility. |
| [Dataverse auditing](https://learn.microsoft.com/en-us/power-platform/admin/manage-dataverse-auditing), [ERPNext v15 Lead schema](https://raw.githubusercontent.com/frappe/erpnext/version-15/erpnext/crm/doctype/lead/lead.json) | Dataverse records changed fields/previous values with audit configuration; the inspected ERPNext v15 Lead schema does not declare track_changes. Tracking must be verified for the actual model/version; neither vendor provides proof of our immutable original-intake contract. |

Synthesis: reasoned history and responsibility handover converge; lifecycle coupling and correction
mechanics differ. A/B/C recommendations are project-specific applications of ADRs 0105–0108,
not vendor requirements. No jurisdictional retention policy or Sales performance-accounting rule
is chosen. This comparison is sufficient for owner review; detailed report/correction design follows.

## Accepted business acceptance — implementation pending

| Decision | Required observable scenarios after approval |
|---|---|
| A | Disqualify with two OPEN deals succeeds with reason and one intake event; deals and commercial facts unchanged. Missing duty/scope/reason refuses. Revival retains decisions; same conversion retry returns the original deal, new conversion refuses while disqualified. Race ordering follows the lead lock. |
| B | Append correction to a wrong historical attribution while outcome remains closed; original event/closure and published evidence unchanged. No correction right or cross-company reference refuses. Concurrent correction refuses stale intent; identical retry creates one correction. Current and corrected-historical views are labelled separately. Named admin assignment is excluded. |
| C | Capture original accepted intake; two corrections including after revival preserve it and old/new evidence. Qualified/disqualified correction changes no status or Party master. No-op adds no event; stale correction/retry/rollback preserve consistency. Original/current/history all obey the same privacy guard. Existing-row baseline is labelled accurately. |

Each approved correction needs an implementation handoff naming Request/Action, database/writer,
Resource/client, demo and screen coverage before coding. Where enabled, detail/history must make
original versus corrected facts understandable; reasoned actions use shared review sheets, retained
input on refusal and refreshed projections. Endpoint/evidence contracts are now in the implementation specification; the B capability/grant
choice alone retains a separate owner gate. No global UI or audit retrofit is authorised.

## Preserved choices and future integration gates

Single Party kind, free-text source/reasons, existing duty vocabulary and valid restricted-mode
403 remain. Expected amount/currency is an unreviewed assumption, never accepted business or a
trusted publication. The security correction is separate and can be handed off immediately.

R6/R9 acceptance scenarios under accepted ADRs 0100–0102/0107:

- Furniture `500000.0000` accepted, installation `300000.0000` still pursued: OPEN with derived
  partial win; neither first acceptance nor linking a quotation closes the whole deal.
- Installation fails, pursuit ends: reasoned WON with accepted evidence and separately recorded
  unsuccessful remainder disposition; maintained assumption remains preserved, not silently zeroed.
- No acceptance: manual WON refuses. Active accepted business: wholly LOST/ABANDONED refuses;
  remaining-scope loss never erases commitment. Correction/cancellation eligibility awaits R6.
- Acceptance triggers review of MAINTAINED assumptions without subtraction. SELECTED_OFFERS
  excludes committed scope under settled overlap rules and never selects a newer revision silently.
- Current raw expected amount, incomplete valuation or provisional forecast contributes no trusted
  published total. Validity returning does not restore trust without explicit review/publication.
- Reopen/correct attribution/close does not cancel jobs/quotes, rewrite publication or restore trust.
- Acceptance-versus-close races lock/revalidate authoritative state and commit outcome/evidence
  atomically; stale intent refuses, retries do not double-count. Hidden evidence stays protected.

These gates are DEFERRED R5/R6/R9 integration obligations, not delivered CRM capabilities or approval of a
partial-acceptance schema. Cost recovery remains separately owned and unchanged.

## Related

- [Implementation specification](phase-4-crm-correction-specification.md) · [CRM correction domain](../domain/crm-corrections.md)

- [Security correction](phase-4-crm-security-correction.md) · [R3 pricing](phase-4-r3-sales-pricing.md)
- [Sales pipeline](../domain/sales-pipeline.md) · [R1 closure](phase-4-r1-decision-matrix.md)
- [ADR 0105](../decisions/0105-support-generic-party-identity-and-conversion.md) · [ADR 0106](../decisions/0106-separate-sales-ownership-and-access.md)
- [ADR 0107](../decisions/0107-separate-opportunity-stages-and-outcomes.md) · [ADR 0108](../decisions/0108-expose-a-progressive-basic-crm.md)
