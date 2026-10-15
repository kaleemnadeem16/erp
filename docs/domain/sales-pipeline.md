[← Domain index](README.md)

# Sales Pipeline

From first contact to a confirmed job.

**R1/R2 business research complete (2026-10-09).** CRM backend foundation exists separately;
quotations/adoption remain unimplemented. The
[accepted decision matrix](../phases/phase-4-r1-decision-matrix.md) is the current foundation contract:

- [0098](../decisions/0098-hybrid-sales-opportunity-requirement.md): optional Lead intake, hybrid
  opportunity policy, STRICT atomic replacement and archive-only legacy records.
- [0099](../decisions/0099-separate-quotation-issue-and-crm-association.md): consistent current
  family association, eligible OPEN targets and immutable per-revision issue linkage.
- [0100](../decisions/0100-independent-quotation-scopes-and-alternative-groups.md),
  [0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md),
  [0102](../decisions/0102-govern-forecast-trust-and-explicit-valuation.md): independent/exclusive
  scope, accepted versus remaining business, explicit forecast basis, persistent review and dated FX.
- [0103](../decisions/0103-issue-quotations-to-party-backed-prospects.md),
  [0104](../decisions/0104-separate-customer-acceptance-and-readiness.md): eligible Party-backed
  issue/valid acceptance before CustomerAccount; no automatic work/credit/financial authority.
- [0105](../decisions/0105-support-generic-party-identity-and-conversion.md): organisation/individual
  contracting identity, controlled reuse/restore/conversion, independent financial roles.
- [0106](../decisions/0106-separate-sales-ownership-and-access.md): eligible accountable owner,
  reassignment history and access separately governed.
- [0107](../decisions/0107-separate-opportunity-stages-and-outcomes.md),
  [0108](../decisions/0108-expose-a-progressive-basic-crm.md): stable outcomes/partial-win indication,
  reasoned closure/reopen, configurable stages and progressive basic CRM.

## Accepted CRM corrections — S, A, B and C implemented

**Delivered (slice S, then A):** each Sales duty is scoped by its own grant (an unrestricted unrelated duty never widens a restricted one) and Party candidate search honours the same scope. A Lead is now disqualified independently of the deals derived from it: `POST /leads/{lead}/disqualify` takes a reason, a `request_key` and the `expected_decision_event_id` the caller saw (the Lead's `decision_event_id`); derived deals are untouched, the prior qualification is recorded in the event, a stale marker is `STALE_EVIDENCE`, the same key and intent replays the original `operation_result`, and a changed intent is `REQUEST_KEY_REUSED`. `LEAD_HAS_OPEN_OPPORTUNITY` no longer exists. **Delivered (C):** every Lead has immutable intake evidence — `ORIGINAL_INTAKE` for a new Lead, or `CAPTURED_BASELINE` with its real capture time and rollout reference for a Lead that predates it. The six enquiry details (`enquirer_name/organisation/email/phone`, `description`, `source`) change only through `PATCH /leads/{lead}` with a reason, `request_key` and `expected_enquiry_version`, in any status; the exact old and new values are recorded, status/partner/assignee/receipt date never change, a no-op creates nothing and keeps its receipt, and `POST /leads` now requires a `request_key`. **Delivered (B):** on a closed deal, the dedicated duty `sales.opportunity.attribution.correct` — together with an independent right to read the pipeline, each judged by its own scope — can append a reasoned correction of one recorded owner fact (who it was created for, or who it was reassigned to): `POST /opportunities/{id}/attribution-corrections` with `target_event_id`, `target_field`, `asserted_owner_id`, `expected_predecessor_id`, `expected_closure_event_id`, `reason` and `request_key`, and `GET /opportunities/{id}/attribution-members` for the narrow member selector (id, name, status; former colleagues included). The opportunity row, the source event and the current owner never change, a head has one successor, a reopen and re-close makes an earlier confirmation stale, and the detail screen shows each recorded fact beside its correction chain. The duty is registered like any capability but is **never** included in an automatic all-capabilities grant (`PermissionRegistry::automaticGrants`): `erp:create-admin --permissions=all`, `erp:sync-admin-permissions` and the demo seeders omit it, an explicit grant survives a re-sync, and only a verified mandate over an existing role (`config('access.full_access_mandates')`, see `FullAccessMandates`) can include it automatically.

Owner-approved [0111](../decisions/0111-disqualify-leads-independently-of-derived-opportunities.md),
[0112](../decisions/0112-correct-historical-opportunity-responsibility-with-append-only-evidence.md)
and [0113](../decisions/0113-preserve-original-lead-intake-and-govern-current-detail-corrections.md)
permit independent reasoned intake disqualification, append-only historical responsibility correction
without reopening, and immutable original intake plus governed current-detail corrections in all
Lead states. Routine closed-record follow-up uses notes; named administration and general closed-owner
editing are excluded. See [evidence model](crm-corrections.md) and [delivery contract](../phases/phase-4-crm-correction-specification.md).
B's sensitive duty/grant boundary is accepted in [0114](../decisions/0114-authorise-historical-attribution-correction-as-a-dedicated-duty.md); safe rollout remains implementation work; the [urgent scope/candidate correction](../phases/phase-4-crm-security-correction.md)
is independently required. The as-built foundation below retains current restrictions until delivery.
Expected value remains unreviewed assumption; partial-win/remaining-business and trusted forecast
remain R5/R6/R9 gates, not delivered capabilities.

## CRM foundation — built (2026-10-09)

The first implementation slice covers only what ADRs 0098 and 0105–0108 settle, with no dependence on quotation structure (R2 onward). Module: [Sales](../../backend/app/Modules/Sales/README.md).

| Concept | As built |
|---|---|
| **Lead** (optional) | The enquiry as received (name, organisation, email, phone, description, source, when), numbered `LEAD-…`, optionally assigned. `NEW → QUALIFIED / DISQUALIFIED`, each a history row with its reason; a revived lead keeps what was decided before. Disqualification does not depend on its derived deals (they stay open). Current details can be corrected in every status, with a reason; the original is never rewritten. May be linked to a Party by an explicit choice; **no customer account is ever created** and nothing is merged. |
| **Opportunity** | A title for a **Party** (and optionally one of its contacts), one accountable **owner** (an active member; defaults to the creator), an OPEN **stage**, optional source, expected close date, manual next action and date, and an optional expected value that is an amount **and** its currency or neither — unknown stays unknown. Numbered `OPP-…`. Created directly or by **converting** a lead; a `conversion_key` makes a retry return the deal already created, and one enquiry may yield several deals. |
| **Stages** | Company-configurable OPEN stages (create, rename, reorder, archive; never deleted; at least one stays active). History snapshots the stage name, so a later rename or archive never reinterprets it. A first-use action offers Qualification → Proposal → Negotiation. |
| **Outcomes** | OPEN, LOST, ABANDONED (with a reason, actor and time), and reopen of the same pursuit with a reason; the earlier closure stays in the history. A closed opportunity cannot be edited, moved or reassigned until reopened (a database trigger). **WON is stored as a valid outcome but refused**: it needs recorded customer acceptance (R6). Partial win is derived from accepted business and so not shown yet. |
| **Ownership** | One owner; reassignment needs its own capability, validates the new owner, and records old and new. An owner who is later suspended is **flagged** (`owner_needs_reassignment`, filter *needs attention*), never silently replaced. Ownership grants no access. |
| **Notes, history** | Authored, timestamped notes; an append-only log of every decision (received, assigned, linked, qualified, disqualified, revived, converted, created, owner and stage changes, closed, reopened). |

**Access.** Capabilities are by duty: `sales.pipeline.view`, `sales.lead.manage`, `sales.opportunity.manage`, `sales.opportunity.assign`, `sales.opportunity.close`, `sales.stage.manage`, `sales.numbering.manage`. Records are company-wide for those who hold one. Restricted
own/assigned/unit visibility is **not built**, so a person an administrator has limited to their units is refused (`403`) on every Sales surface rather than shown a filtered list. Nothing is granted by inference at release: administrators assign the capabilities, and the demo personas show the intended role recipes.

**Role recipes and demo personas** (all password-reset-free demo users from `demo:seed`, created idempotently by `SalesCrmDemoSeeder`; a role is a bundle of duties, and the name confers nothing):

| Persona | Duties | Shows |
|---|---|---|
| `demo-sales-rep@…` Sales representative | view, lead and opportunity manage, party register | Records enquiries, works deals; cannot reassign, close or configure |
| `demo-sales-manager@…` Sales manager | the representative's, plus assign, close, stage manage | Reassigns an owner who has left, closes and reopens, renames stages |
| `demo-sales-viewer@…` Sales viewer | view only | Reads the pipeline; every control that would change it is absent |
| `demo-sales-branch@…` Branch seller (limited) | work duties limited to *my units* | Refused (`403`) until restricted visibility exists, never shown a wrong list |
| `demo-sales-leaver@…` | none (suspended) | Owns a deal that therefore needs a new owner |

The demo data shows every state: a new unassigned enquiry, a qualified one that produced two deals, a disqualified one, a direct deal with a known value and next action, a lost deal, and a deal whose owner has left.

**Opportunity screens — coverage against the backend** (the [screen delivery contract](../architecture/screen-delivery-contract.md) matrix; list, detail sheet and create sheet). Verified 2026-10-15 against `OpportunityResource`, the Store/Update/Index/Close/Reassign/Stage requests and routes.

| Backend element | Where it appears | Notes |
|---|---|---|
| Filters `outcome`, `stage_id`, `owner_id`, `party_id`, `search`, `mine`, `needs_attention` | Beside search: outcome, stage, Mine, Needs a new owner. Advanced panel (phone: bottom sheet): trading partner, owner | Active chips with removal and Clear all; all in the URL |
| Sorts `created_at`, `number`, `title`, `expected_close_on`, `next_action_on` | Column headers; *Created* is an optional column so its sort stays reachable | Direction shown on the header |
| Resource facts | Default columns: number, title, for, stage, owner, value, expected close, next action, outcome. Optional columns: source, came from an enquiry, description, created, last updated, closed on, closure reason | Dates past due on an open deal read "overdue"; every fact is also on the detail (empty ones say "Not set") |
| Actions: stage, reassign, close, reopen, notes, edit, attribution correction | Detail sheet (permission- and state-gated). Bulk, on the ticked rows of one page: change owner, move stage, close — each its own request, open deals only, closed ones counted and skipped, failures named and retryable | Reopen, notes, edit and the attribution correction stay single-record on purpose (each needs its own reason or evidence) |
| Print | List (visible columns, filters recorded on the paper), selected rows, and the record with notes and history | Quick Print; a formal quotation document is R8 |
| Update field `source` | Edit dialog and create form | |
| `party_contact_id` (create, update, and converting an enquiry) | *Contact person* picker once a partner is chosen, with *Add contact…* on the spot; shown with role, phone and email on the deal and its print. Offered only to someone whose duty involves choosing or maintaining a Party; anyone else sees "One is set (you cannot see contacts)" and an edit leaves the contact untouched | Uses the Parties contacts API ([parties.md](parties.md#contacts)) |
| `WON` on close | Not offered: refused until customer acceptance (R6) | |
| `lead` include on the index | Not available: the list endpoint allows only party, stage and owner | List shows a yes/no "came from an enquiry"; the detail links the enquiry |

**Enquiry screens — coverage** (same contract; verified against `LeadResource`, the Store/Update/Index/Assign/Qualify/Disqualify/Revive/Convert requests and routes).

| Backend element | Where it appears | Notes |
|---|---|---|
| Filters `status`, `assigned_to`, `party_id`, `search`, `unassigned` | Beside search: status, Unassigned toggle. Advanced panel (phone: bottom sheet): trading partner, assigned to | Chips with removal, Clear all, all in the URL |
| Sorts `received_at`, `number`, `created_at` | Column headers; *Recorded* is an optional column | |
| Resource facts | Default columns: number, enquirer, organisation, source, assigned to, received, status. Optional: email, phone, what they want, trading partner, qualified on, disqualified on, disqualification reason, recorded | Detail shows every fact; ones not provided say "Not provided" |
| Create fields | Name, organisation, email, phone, source, description; under *More options*: received on, trading partner (with Register new), assign to | `request_key` per submission |
| Actions | Detail sheet: qualify, disqualify, revive, assign, link partner, create opportunity, add note, correct details (version + reason). Bulk on one page: assign, qualify, disqualify, revive, each only for the enquiries it fits, one request each with named, retryable failures | Convert, link partner, notes and correction stay single-record: each needs choices or evidence of its own |
| Print | List (visible columns and filters), selected rows, and the record with opportunities, notes and history | Quick Print |

**Pipeline stage screens — coverage** (`SalesStageResource`: code, name, sort order, status; routes index, store, update, seed-suggested).

| Backend element | Where it appears | Notes |
|---|---|---|
| Index (no filters, sorts or paging: the endpoint returns the whole short list in order) | Search (name or code) and a status filter, as chips with Clear all, in the URL; paging on the loaded list; position shown is the place in the full order | These work on what is loaded because the set is bounded configuration, not data that grows |
| Create `code`, `name`, `sort_order` | Side panel: name; under *More options* the code (taken from the name) and a position value | A code is a stage's fixed identity: set once, never edited |
| Update `name` | Rename panel | |
| Update `sort_order` | Move up / Move down in the row menu and on the stage's panel | Down is the stage below moving up |
| Update `status` | Archive / Restore on the row, the panel, and in bulk on the ticked rows (one request each; the server refuses an archive that would leave no active stage, reported by name) | Never deleted |
| Seed suggested | Empty state | Qualification, Proposal, Negotiation |
| Print | List (filtered), selected rows, and one stage | Quick Print |
| No single-stage endpoint | The panel reads the stage from the list | |

**Editing a deal.** Create and edit are the same side-panel form (`/opportunities/:id/edit`; open deals only; who it is for, owner and stage change through their own actions). *Quick update* is a small dialog over the record for the follow-up only (value, expected close, next action and date).

**Company size.** One stage needs no stage control, column or filter, and one possible owner needs no owner column, filter, "Mine" or change-owner action (for enquiries: no assignee column, filter, Unassigned toggle or bulk assign); all of them appear as soon as there is a choice (a second stage, a second active member, or a deal whose owner has left). No setting is involved — the pipeline simply grows with the company.

**Deferred on purpose** (planning decisions or later topics): quotation association and the opportunity-requirement policy (ADR 0098's FLEXIBLE/MANAGED/STRICT setting has no effect without quotations), accepted business and partial win (R6), forecast and the disposition of remaining potential (ADRs 0101/0102, R9), restricted visibility (R9), notifications (Q33), automation, teams, campaigns.

**The schema and behaviour sketch below are historical planning, not an accepted physical model.**
Customer-only recipient fields, one mutable opportunity link, simplistic win/job transitions and
numbering assumptions must be reconciled by R2–R9 before implementation. R1 does not choose tables,
revision mechanisms, partial-acceptance calculations, job multiplicity or accounting gates. Advanced
capabilities remain deferred. See the [Phase 4 plan](../phases/phase-4-sales-quotations-and-handover.md).

**R2 direction approved and bounded research closed (2026-10-09):** [ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)
establishes shared Item/service/Assembly/manual scope, distinct measures, flat presentation, explicit
options, optional governed estimates and Party-backed contexts. The [compatibility assessment](../phases/phase-4-r2-compatibility-audit.md)
preserves current Estimating functionality; the [delivery plan](../phases/phase-4-r2-compatibility-plan.md)
passed owner review. [C0/C1/C2 technical proposals](../phases/phase-4-c0-cost-evidence-contract.md)
await contract approval and implementation authority; quotation implementation/schema remain deferred.

## Current quotation lifecycle authority

R5 L1–L6 is accepted in [0122](../decisions/0122-govern-quotation-candidates-adoption-and-revalidation.md),
[0123](../decisions/0123-issue-numbered-quotation-revisions-atomically.md) and [0124](../decisions/0124-preserve-quotation-availability-and-scope-replacements.md).
The [quotation lifecycle contract](quotation-lifecycle.md) now governs family/logical-scope/revision,
source candidates/adoption, shared review, atomic official issue, original financial/expiry evidence
and whole-unaccepted availability/replacement. The old schema below remains historical, including
its number/revision unique and implicit win/job sketch; it is not the new physical model.
R6 C1–C8 is accepted0125–0127: [customer-decision contracts](sales-customer-decisions.md) and
[CA0–CA6 slices](../phases/phase-4-r6-implementation-slices.md) are specified, not delivered.
Ordinary acceptance is separate from operational readiness; R7 targets/corrections and R8/R9 gates
remain. Partial-family operations still require R6/R7 approval and delivery; no quotation application delivered.

## Where it sits

Consumes [assemblies.md](assemblies.md); produces the jobs tracked in
[job-costing.md](job-costing.md). Built in [Phase 4](../phases/README.md).

## Schema

```
leads
  id, company_id
  name, company_name, email, phone, source
  status          NEW | CONTACTED | QUALIFIED | DISQUALIFIED
  owner_id, custom_fields

opportunities
  id, company_id, customer_id NULLABLE, lead_id NULLABLE
  name, estimated_value, currency_id
  stage, probability_pct, expected_close_date
  owner_id

quotations
  id, company_id, customer_id, opportunity_id NULLABLE
  number                              -- from sequences, gapless
  revision_no
  supersedes_id   NULLABLE            -- previous revision
  quotation_date, valid_until
  currency_id, fx_rate
  status          DRAFT | ISSUED | ACCEPTED | REJECTED | EXPIRED | SUPERSEDED
  subtotal, tax_total, total, base_total
  terms, notes, custom_fields

  unique(['company_id', 'number', 'revision_no'])

quotation_lines
  id, quotation_id
  assembly_version_id  → assembly_versions
  description                          -- "Exterior wall, north elevation"
  qty, work_uom_id                     -- 250 sqm
  dimension_values     json            -- resolved rate context, e.g. {surface: rough}
  cost_snapshot        json            -- FULL resolved breakdown — immutable
  unit_cost, margin_type, margin_pct
  unit_price, line_total
  sequence
```

## Behaviour

### Lines are scope items, not products

A quotation line describes work:

> *"Exterior wall, north elevation — 250 sqm, Shade #4471, 2 coats, rough plaster"*

It references an assembly version, a quantity in the work UoM, and the dimension values needed
to resolve [application rates](application-rates.md).

### Revisions

Customers negotiate. A revision creates a **new quotation row** with `revision_no + 1` and
`supersedes_id` pointing at the previous one, which becomes `SUPERSEDED`.

Superseded revisions remain fully readable. "What did we quote in rev A?" must always be
answerable.

### The snapshot

On transition to `ISSUED`, each line's `cost_snapshot` is populated with the **fully resolved
breakdown**: every component, its quantity, its unit cost, the labour rates used, which
application rate was selected, and the FX rate.

This is [invariant 1](../architecture/invariants.md#1-snapshot-on-issue) and the most important
rule in the sales module. A `DRAFT` quotation recomputes live; an `ISSUED` one never does.

When material resolution is package-aware, the snapshot also keeps the recommendation strategy
and version, availability as-of time, generated suggestion, final user selection and internal-cost
evidence. This preserves what the estimate assumed; it does **not** reserve those packages.
Phase 5 revalidates and reserves physical stock. Customer selling price remains a separate Sales
decision even when internal package cost informed the estimate. Its resolver and issued-document
evidence are defined in [sales-pricing.md](sales-pricing.md): source/rule version, typed inputs and
intermediate results, calculated and final prices, rounding and any authorised override.

An adopted standard-to-planned material variance also snapshots ADR 0054's policy evaluation,
actor and any required reason/approval. A material change re-evaluates the policy and cannot carry
an approval from the earlier revision onto changed facts.

### Confirmation

Accepting a quotation creates a **job** ([job-costing.md](job-costing.md)), carrying the
snapshot forward as the estimate baseline. Fires `JobConfirmed`, which
[production and inventory listen to](../architecture/code-structure.md).

### Numbering

From the `sequences` table with row locking. Gapless —
[invariant 8](../architecture/invariants.md#8-document-numbers-are-unique-issued-by-one-framework-and-gapless-only-where-a-series-says-so).

## Landmines

**Editing an issued quotation.** Must be blocked. Changes create a revision. Without this the
snapshot is meaningless.

**Losing the dimension context.** If a line does not record *which* application rate was
resolved, the cost cannot be explained later — only reproduced, and only if nothing changed.
Store the resolution, not just the result.

**Quote validity.** `valid_until` exists because costs move. An expired quotation should not be
acceptable without re-costing, and that check belongs in the confirmation Action.

**Partial acceptance.** Customers accept some lines and not others. Decide whether that creates
a revision or a partial job — this is a real workflow question that surfaces immediately in
practice and has no obvious default.

## Related

- [Accepted R5 quotation contract](quotation-lifecycle.md)

- [material-resolution.md](material-resolution.md) — cost/price separation and planned material
  snapshot boundary
- [sales-pricing.md](sales-pricing.md) — Phase 4 price resolution and quotation price snapshot
- [crm-corrections.md](crm-corrections.md) — accepted enquiry/responsibility correction evidence
- [tax.md](tax.md) — tax determination after net price
- [inventory-packages.md](inventory-packages.md) — advisory package proposal and snapshot evidence
- [ADR 0054](../decisions/0054-risk-based-material-plan-variance-controls.md) — adoption audit and
  risk-based explanation/approval/blocking
- [assemblies.md](assemblies.md) — what lines reference
- [job-costing.md](job-costing.md) — what confirmation produces
- [receivables.md](receivables.md) — invoicing against the job
