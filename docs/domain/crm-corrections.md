[← Domain index](README.md)

# CRM correction evidence

Accepted ADRs 0111–0114 distinguish intake disposition, corrected enquiry details and historical
responsibility from current pursuit. **DECISIONS ACCEPTED AND IMPLEMENTED: the operation-receipt table, keyed independent
disqualification (A), intake/enquiry-correction evidence (C) and attribution corrections (B), with the
security correction (S) ahead of them.** Historical-attribution authority is accepted in 0114; registry/grants/re-sync remain implementation work. The existing CRM
foundation still has its old restrictions until the implementation agent delivers these corrections.

## Where it sits

Lead receipt → immutable intake evidence + current enquiry → reasoned enquiry corrections.
Opportunity responsibility events → append-only attribution corrections → explicitly labelled
historical interpretation. Party master, accepted business and published forecast remain independent.
The [delivery specification](../phases/phase-4-crm-correction-specification.md) owns API/UI, writer
integration, acceptance and rollout tasks; this file owns the evidence model and invariants.

## Schema — implementation contract, not deployed pseudo-DDL

```
sales_leads                         -- existing table
  enquiry_version bigint >= 0       -- added; advances only when enquiry values change

sales_lead_intakes
  id, company_id, lead_id
  evidence_kind ORIGINAL_INTAKE | CAPTURED_BASELINE
  captured_at timestamptz            -- actual evidence capture, never backdated
  captured_by user id NULL           -- null only for labelled system rollout baseline
  capture_reference text NULL       -- required for system rollout provenance
  received_at timestamptz            -- accepted/previously recorded receipt date, not capture date
  recorded_creator_id user id        -- copied recorded creator, not claimed proof of historic capture
  enquirer_name varchar(200)
  enquirer_organisation varchar(200) NULL
  enquirer_email varchar(200) NULL
  enquirer_phone varchar(64) NULL
  description text NULL, source varchar(100) NULL
  unique(company_id, lead_id), unique(company_id, id)

sales_lead_enquiry_corrections
  id, company_id, lead_id, actor_id, recorded_at timestamptz
  reason text, previous_version bigint, resulting_version bigint
  unique(company_id, lead_id, resulting_version), unique(company_id, id)

sales_lead_enquiry_correction_fields
  id, company_id, correction_id
  field_name                         -- six allowed enquiry fields above; excludes received_at
  old_value text NULL, new_value text NULL
  unique(company_id, correction_id, field_name)

sales_opportunity_attribution_corrections
  id, company_id, opportunity_id, target_event_id
  target_field OWNER_ID | TO_OWNER_ID -- only created-owner or reassignment destination facts
  predecessor_id NULL               -- first correction null; later refers to current correction head
  recorded_owner_id, asserted_owner_id user ids
  actor_id, recorded_at timestamptz, reason text
  unique(company_id, id)
  unique(company_id, opportunity_id, target_event_id, target_field) WHERE predecessor_id IS NULL
  unique(company_id, predecessor_id) WHERE predecessor_id IS NOT NULL

sales_crm_operation_receipts
  id, company_id, actor_id, request_key varchar(85), operation
  lead_id NULL, opportunity_id NULL  -- exactly one applicable subject
  payload_hash char(64), completed_at timestamptz
  result jsonb                      -- versioned operation-result evidence ids/status, not raw request
  unique(company_id, actor_id, request_key), unique(company_id, id)
```

Use typed core fields; JSON receipt result is a serialised response/evidence reference, not a
second mutable domain model. All new independent evidence tables carry company ids and composite
company foreign keys to subject, actors, corrected members and parent correction. Add necessary
composite unique keys to referenced existing evidence (`sales_events`) additively. Target event
and predecessor must belong to the same Opportunity/fact, not merely the same company; database
validation also checks event-kind/field mapping and recorded_owner_id against the frozen source fact. Index
subject/time/id and target/head queries; preserve immutable evidence rows on user suspension.

## Behaviour

Disqualification needs Lead-management authority, reason and transaction; valid derived deals
remain untouched. Preserve prior qualification in events before clearing any current qualification
projection. Revival is a separate decision and does not recreate pursuits or wipe intake evidence.

New Leads have one ORIGINAL_INTAKE copied from the actual accepted normalised enquiry. Existing
rows without provable original fields get CAPTURED_BASELINE with actual capture time and rollout
reference; keep existing history unchanged. Do not infer originals from current Party/contact.
Original evidence records the existing receipt date separately from capture time and limits claims
of authenticity accordingly. Full original recovery from historical records is excluded from the
initial rollout; accurately retained prior events remain readable as prior evidence.

Corrections change only the six current enquiry fields. They work for NEW, QUALIFIED and
DISQUALIFIED, including revived intake. Every changed field has previous/new evidence and a
nonblank reason. Current status, Party links, assignee, receipt date, derived deals and original
intake stay unchanged. Null clearing and unknown values remain distinct from empty text according
to existing normalisation. No-op creates no correction/version increment, but retains its retry receipt.

Historical attribution correction initially targets a single recorded owner fact in an
OPPORTUNITY_CREATED or OWNER_CHANGED event for a currently closed Opportunity. Appending it
never changes `owner_id`, the source event, its actor/time, stage/outcome/closure or any issued
snapshot. A second correction points to the current correction head. The corrected historical
view overlays the explicit fact; the recorded view stays intact. Do not reinterpret actor_id or
closed_by as owner, invent a period or silently repair adjacent recorded fields. Arbitrary interval
correction, performance/commission recalculation and report publication are excluded initially.

Where a closure-time owner was not explicitly recorded, show the original responsibility timeline;
no new correction fabricates a closure snapshot. Reports using the overlay must say which fact and
recorded/corrected basis they use. Future as-of reporting distinguishes fact time from correction
recording time. Existing ordinary ownership filters/owner labels continue using current owner.
Routine closed administrative follow-up remains authored notes; no administrative assignee.

## Authorisation and data scope

A and C reuse `sales.lead.manage` with its own effective scope at Request and Action; original,
current and correction histories use existing legitimate pipeline-read duties and identical scope.
Intake correction confers no Party or financial-master authority. Preserve independent Party rights
and additive grants under the [urgent security correction](../phases/phase-4-crm-security-correction.md).

B requires accepted `sales.opportunity.attribution.correct` AND independent legitimate Opportunity
read eligibility (0114); this is approved vocabulary, not evidence of registration/implementation.
Assignment, editing, closure, stage maintenance, role names and ownership do not imply it. Restricted
Sales modes refuse (403); other-company ids remain unreachable under existing conventions. Original
and corrected historical facts follow the same read/sensitive-field protection. The correction duty
alone must not silently add pipeline view or user-directory visibility. Explicit grants are for designated
authorised CRM/company administrators; ordinary Sales duties/managers gain nothing automatically.
Administrator re-sync includes it only under an explicitly defined existing full-access role mandate,
not a name or current permission snapshot. Preserve legitimate explicit grants and effective scope;
missing qualifying mandate excludes automatic inclusion.

## Integrity and refusals

Evidence and operation receipts are append-only with database UPDATE/DELETE refusal. Database
checks require nonblank reasons, valid event/field/member references, unique root/successor and
resulting_version = previous_version + 1. Delta fields must actually differ, with valid lengths and
nonblank corrected name. Deferred checks prove correction/projection consistency; a current enquiry
change without its exact old/new evidence is refused, including raw SQL. One intake exists before
a Lead receipt transaction commits. Existing closed-Opportunity immutability stays intact.

Use one guarded Action/transaction, company/actor-scoped key plus normalised payload hash, then
subject lock before evidence/chain reads. Same completed intent replays its result under current
authority; changed intent refuses. Correction tokens are version/head references, never timestamps
alone. Missing/stale tokens do not silently rebase; `STALE_EVIDENCE` permits refresh/review.
Canonical operation results are retained independently of later live projections.

Stable actionable codes: `REASON_REQUIRED`, `STALE_EVIDENCE`, `LEAD_DISQUALIFIED` and
`WON_REQUIRES_ACCEPTANCE` retain their existing meanings. Proposed additions:
`REQUEST_KEY_REUSED` (changed intent), `ATTRIBUTION_TARGET_UNSUPPORTED` (choose recorded owner fact),
`ATTRIBUTION_REQUIRES_CLOSED` (pursuit state changed). API/global refusal mappings stay established.
`LEAD_HAS_OPEN_OPPORTUNITY` ceases to apply to disqualification; do not reuse it for a new cause.

## Landmines

- Reopening to correct spelling/attribution invents a lifecycle event.
- Copying today's values into an ORIGINAL_INTAKE fabricates history; generic audit defaults are
  insufficient evidence for the current guarded-only Lead model.
- Correcting owner attribution is not editing event actor, current owner or financial entitlement.
- A latest corrected view must not silently change an already published historical report.
- A correction duty combined with unrelated unrestricted scope would repeat the urgent access defect.

## Related

- [Sales pipeline](sales-pipeline.md) · [Correction specification](../phases/phase-4-crm-correction-specification.md)
- [0111](../decisions/0111-disqualify-leads-independently-of-derived-opportunities.md)
- [0112](../decisions/0112-correct-historical-opportunity-responsibility-with-append-only-evidence.md)
- [0113](../decisions/0113-preserve-original-lead-intake-and-govern-current-detail-corrections.md)
- [0114](../decisions/0114-authorise-historical-attribution-correction-as-a-dedicated-duty.md)
- [As-built implementation handoff](../phases/phase-4-crm-corrections-implementation.md)
- [Domain integrity](../architecture/domain-integrity.md) · [Authorisation](../architecture/authorization.md)
