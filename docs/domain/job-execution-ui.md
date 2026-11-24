[← Domain index](README.md)

# Jobs interactive execution

Owner-authorised 24 November 2026 (#132), on the independently approved [Jobs command API](job-write-api.md).
All 23 Jobs duties remain SPECIFIED, with zero ACTIVE duties. Screens consume effective duties;
API Requests and existing Actions remain authoritative. Assignment grants no access or other duty.

## Actors and entry

Readers keep the existing list, detail sheet/page and complete history. Each workflow operation
is independently guarded by its exact duty. A command-only employee uses `/job-operations` with a
known numeric Job reference or Job link and selects one permitted operation; no general Jobs,
Sales, member administration or financial read is needed. The reference is confirmed by the Job
number returned by the source-eligible operation context. Unknown/unavailable records never
fall back to broad reads or a general member directory.

The owner explicitly requested a separate minimal command entry screen. It is a task page, not a
replacement Job record/detail presentation; existing Job details remain sheets by default. Dialogs
perform one short operation over its loaded, command-specific context. List filters and scroll
context remain mounted behind the existing detail sheet.

## Coverage matrix

| Contract element | UI destination | Availability | Acceptance |
|---|---|---|---|
| Assign | Exact-duty action dialog; searchable assignee | job.assign; RELEASED/ACTIVE; no open hold, finally checked by Action | Confirm new responsibility; target remains active/current company |
| Start | Exact-duty confirmation | job.start; RELEASED, current employee is assigned, no holds | Success refresh; held/unassigned/stale refusal never changes state optimistically |
| Hold | Reason and confirmation | job.hold; RELEASED/ACTIVE | Independent new hold; retry preserves command identity |
| Resolve hold | Choose one open hold, reason and confirmation | job.hold.resolve; named same-Job open hold | Resolving one leaves every other hold open |
| Complete | Whole-deliverable attestation | job.complete; ACTIVE; no holds; frozen attestation context | Review the deliverable and conditions, enter a statement and explicitly confirm |
| Assess closeout | Explicit assessment confirmation and READY/BLOCKED result | job.close; eligible source, including unready Jobs | Stable blocker words and next steps; no source pins/evidence |
| Close | READY assessment of current generation, optional statement, final confirmation | job.close; COMPLETED, no holds; Action rechecks readiness | Operational closure is irreversible; changed assessment/source refuses |
| Generation/key/UUID | Bound command intent, not editable fields | Every command; assessment has actor/key identity only | Same-intent uncertain retries keep all identities and generation |
| Reason / statement | Labelled action-local field | Required/optional exactly as Request | Inline server errors; no duplicate submit; retain input after failure |
| Intended assignment dates / instructions | Optional assignment details | Assignment only | Offset-bearing timestamps and end/start ordering; no server recorded-time input |
| Claimed occurrence / precision | Optional command details | Lifecycle commands only; absent by default | Explicit offset converted to UTC; no invented precision vocabulary |
| Job number/state and operation facts | Minimal selected-operation panel | Current exact duty AND source eligibility | Only the #128 profile appears; no general worksheet/source/customer history |
| Eligible assignees search | Shared remote combobox | job.assign and current same-Job source eligibility | Name search ≥2 characters; keyboard; bounded pages; selected label retained |
| Existing list filters/sorts/history | Existing Jobs screens | job.view | Preserved; actions refresh affected queries and keep URL context |
| Bulk, public creation, costing, cancellation, reopening | Not applicable | Outside authorised scope | No new controls, routes or duties |

## Retry and recovery

A submit is a server-confirmed operation, never an optimistic transition. An uncertain network or
server failure retains the original request key, UUID, generation and body for an exact retry.
Changing intent deliberately creates fresh identities. Pending submits cannot be duplicated.
After a confirmed success or stale/refused operation, refresh the command context and affected
Jobs list/detail/history; authority revocation refreshes the authenticated effective duties and
clears protected cached context. A changed context requires a fresh review/confirmation.

A close assessment is immutable evidence, not a promise that closing will succeed. READY is tied
to its Job and generation; BLOCKED shows safe stable blocker advice. Source/fulfilment evidence
is never rendered. The existing close Action rechecks all conditions under its existing locks.

## Eligible-assignee lookup

`GET /api/v1/jobs/{job}/eligible-assignees?search=...&page=1&per_page=20` requires exact ACTIVE
job.assign, current active company membership and that duty's ALL scope in Request and query.
The query reuses the existing ASSIGN operation-context boundary inside the same outer read
transaction, retaining company/source fences through employee materialisation and the final
authority recheck. No employee query/count precedes source eligibility. Missing/foreign/unsupported
or unproved source records get the same generic Job 404; authority is 403.

Search is a required literal name substring of 2–100 characters. Pages default to 20, maximum 50;
results are active same-company members ordered by name/id, with exactly id/name per choice.
No email, status, role, permission, organisational assignment or member-admin endpoint is exposed.
This is a same-Job assignment selector. The existing Assign Action independently rechecks the
chosen employee and remains the sole authority for final assignment eligibility.

## Completion attestation review boundary

The existing command profile intentionally contains no baseline text. The approved separate
contract is `GET /jobs/{job}/completion-attestation-context`, with only
job_id, number, state, generation, baseline_version, deliverable and agreed_conditions. It requires
exact ACTIVE job.complete / current membership / ALL scope plus owning-source eligibility under
the existing company/source fences. The text comes only from the frozen Jobs
baseline component's deliverable_text and agreed_conditions. Customer identity, commercial values,
source references, hashes, pins, private consent evidence and broad history remain excluded.
The command uses the disclosed snapshot generation and the existing completion Action. A fresh start requires the current assignee. An otherwise authorised original starter can recover their recorded result after reassignment. Confirmation applies to the reviewed profile and must be renewed when its generation or attestation text changes.

## Related

- [Jobs command API](job-write-api.md)
- [Jobs read API](job-read-api.md)
- [Completion and closeout](job-completion-closeout.md)
- [Jobs module](../../backend/app/Modules/Jobs/README.md)
- [Screen delivery contract](../architecture/screen-delivery-contract.md)
- [Phase 5](../phases/phase-5-job-execution.md)
