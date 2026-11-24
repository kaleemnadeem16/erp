[← Journeys](README.md)

# Carry out a Job operation

Actors hold individual Jobs duties. A reader may also have `job.view`; an operator does not need
it. All shipped Jobs duties remain disabled until the owner separately approves actual activation
and holders. Assignment itself grants nothing.

## Find the right entry

- A reader opens **Jobs**, keeps the current filters, and uses the row action menu or the detail
  sheet's individually guarded operation buttons. History remains a read-only record of what happened.
- A command-only employee opens **Job operations**, enters a known numeric Job reference or pastes
  a Job link, and chooses a permitted operation. The authorised Job number and state are confirmed
  before any write. This entry never loads the general Jobs list, worksheet, customer or Sales record.

A missing, foreign or source-ineligible Job is unavailable without explaining which record exists.
Missing/revoked duties refuse the operation; the interface refreshes current effective access.

## Review and confirm

Each operation loads its own minimal current context. Review the Job number, state and relevant
facts, enter required fields, then explicitly confirm. The server's recorded result establishes
success. An uncertain network result keeps the original body, generation, request key and command
identity; retry the original request to establish its outcome. Inputs stay locked while uncertain.
A stale-generation refusal refreshes context and requires a new confirmation. Closing a dirty
form requires explicit discard; browser reload warns while work is unsaved or unresolved.

| Operation | Employee decision | Result and exception |
|---|---|---|
| Assign responsibility | Search eligible employees by name; page through matches, choose one; optionally record intended dates and instructions | Records responsibility only. A person suspended or otherwise ineligible before submit is refused by the existing Action |
| Start work | Confirm recorded responsibility and no open holds | RELEASED → ACTIVE; an unassigned/held/stale Job refuses |
| Place on hold | Give a reason for one new hold | Independent hold; further holds stay distinct |
| Resolve a hold | Choose one named open hold and give the resolution reason | Only that hold resolves. Other holds stay open; work does not start automatically |
| Complete deliverable | Review the exact frozen deliverable and agreed completion conditions before attesting the whole work | **Deferred:** the minimal authorised attestation-context disclosure contract needs owner review; the UI cannot attest unseen conditions |
| Assess closeout readiness | Confirm recording an assessment | READY or BLOCKED immutable assessment; safe blocker advice identifies the next responsible step |
| Close Job | Obtain a READY assessment for the current generation; optionally state the closeout reason; confirm permanent operational closure | COMPLETED → CLOSED; changed conditions/source/fulfilment or stale assessment refuse. No invoice/balance is settled |

Optional claimed occurrence is labelled separately from server recorded time and requires an
explicit timezone offset. No arbitrary state editing, bulk operation, public Job creation, costing,
cancellation, reopening or automatic permission assignment is provided.

## Accessibility and acceptance

Use the shared keyboard-accessible remote combobox, dialogs, labels, status badges and screen
shell in light/dark modes and at phone width. Tests exercise exact-duty entry, search/pagination,
confirmation and pending protection, retained retry identities, stale refresh, independent holds,
closeout outcomes and revocation. Browser fixtures intercept only the API boundary and use the
production React components and generated clients; real source/Action/transaction behaviour is
covered independently by the Jobs backend regression and committed fence-wait tests. No real-user
permission activation is performed to enable the test journey.

## Related

- [Jobs interactive execution contract](../domain/job-execution-ui.md)
- [Jobs command API](../domain/job-write-api.md)
- [Review Job progress](review-job-progress.md)
- [Phase 5 plan](../phases/phase-5-job-execution.md)
