[← Domain model](README.md)

# Jobs read-only API

The HTTP layer reuses `ReadJobs`, `JobReadFilter` and the materialised
`JobReadProjection`. All shipped Jobs duties remain SPECIFIED. The endpoints deny
production users even with handcrafted permission rows or full-access mandates.
Activation and holder assignment require a separate owner-approved rollout.

## Contract

| GET endpoint (under `/api/v1`) | Result |
|---|---|
| `/jobs` | Page of safe operational summaries; default 25, maximum 100 |
| `/jobs/{job}` | Safe operational detail |
| `/jobs/{job}/history` | Page of operational events; default 50, maximum 100 |

Resources use the standard `data` envelope; pages include `meta` and `links`, retaining
query parameters. Invalid/overflowing record ids return 404 after request authorisation;
prohibited includes/filter/sort parameters are omitted from OpenAPI/client signatures. Positive page numbers are capped at 1,000,000.
List filters use `filter[search]` (literal substring of number/short description, ≤200
characters), `state` (RELEASED/ACTIVE/COMPLETED/CLOSED), `responsible_member_id`
(latest assignment), `has_open_hold` (true/false or 1/0), `created_from` and
`created_until` (inclusive UTC calendar dates). Dates must be valid `YYYY-MM-DD` and
an end before the start is refused. Unknown filter keys are refused.

`sort` accepts `number`, `state`, `created_at`, `released_at`, `started_at`, with `-`
for descending; default `-created_at`. NULLs sort last and id breaks ties. History is
always ascending server sequence/id, independently of claimed occurrence time;
it refuses filters, includes and caller-specified sorting. Unsupported includes and
protected-field filter/sort requests return 422. No relation expansion is supported.

The existing projection exposes number, state, short description, current responsible
member, open-hold flag and creation/release/start times. Detail adds the baseline's
accepted scope/conditions, assignment window, open holds and default activity. A Sales
family/revision reference appears only with independent current quotation read access.
History includes RELEASE/ASSIGN/START/HOLD/RESOLVE_HOLD/COMPLETE/CLOSE
events with labelled claimed occurrence time and actor reference. Detail includes completion
and operational closeout statements, recorded event times and separately labelled claimed
occurrence/precision, from immutable evidence joined by company, Job and event, bounded
by the captured head generation. Responses omit commercial amounts, costs, internal hashes, source snapshots,
private customer consent/evidence and request receipts.

## Authorisation and completion applicability

- Exact `job.view` is required for all three reads; closing/assignment or Sales duties
  confer no Jobs read authority. Authentication alone is insufficient (401/403).
- Requests call `JobAccess`; `ReadJobs` repeats current authority under the company access
  fence. The duty must be ACTIVE, the employee currently ACTIVE in this company, the exact
  permission held and its scope ALL. Limited scopes refuse 403. Revocation, suspension and
  inactive duties refuse before Job lookup. No maker/checker transition is involved.
- SQL is company-scoped. Missing and foreign ids map `JOB_NOT_FOUND` to identical HTTP 404
  bodies. No route model binding reveals a record before authority.
- No new capability, grant, seed, migration, money, write, state transition, approval,
  idempotency contract, background job, import/export or notification is introduced.
  Existing read semantics are reused; no new business decision or research is required.
- Requests, explicitly typed Resources, OpenAPI and the generated client cover the HTTP
  contract. API tests cover authority, scopes, tenancy, input, pages, ordering and safe
  output; direct-read tests retain deeper projection and fenced security checks.
- Read-only screens use isolated browser fixtures for authorised configurations; demo
  activation is excluded. All duties remain disabled without registration or grants.
- Shared-checkout checks use explicit paths on develop; PHP lint, static analysis, module
  and security/API regression, client drift and frontend compilation are delivery checks.

## Read-only screens

Authorised `job.view` employees use `/jobs` to find operational work and open
`/jobs/{id}` as a record sheet (a page alternative is registered). Current-user
permissions omit `job.view` unless the exact ACTIVE duty, active company membership
and ALL scope pass `JobAccess`; navigation, direct screens and requests use that
same effective permission. Backend Request/query guards remain authoritative.
All shipped duties remain SPECIFIED; isolated test hosts supply active fixtures,
never production grants or a development activation switch.

| Contract | UI destination | Availability and acceptance |
|---|---|---|
| Number, short description, state, responsible employee, open hold, created/released/started dates | Shared table/mobile column catalogue | `job.view`; optional date columns and saved visibility/view; no financial columns |
| Search number/short description, state, hold, responsible employee id, inclusive creation dates | Search/status toolbar and shared Filters sheet; removable chips | Server requests; filter changes reset page; Clear all; invalid input shows server refusal |
| All five sort fields, ascending/descending; page and size | Shared table headers/sort control and pagination | URL state survives opening/closing records; no client-side paging |
| Original agreed scope, deliverable and conditions; baseline version/time | Original agreed work section | Immutable accepted baseline; missing facts say Not recorded |
| Assignment, employee/id, intended window; default activity | Overview and assignment sections | Current immutable assignment, not guessed from first-page employee data |
| Open holds and effective/recorded times | Holds section | Reasons remain plain escaped text; empty means no open holds |
| Completion and operational closeout | Separate detail sections | Immutable statements and recorded event times; optional claimed occurrence/precision explicitly labelled; absence is not inferred from state |
| RELEASE, ASSIGN, START, HOLD, RESOLVE_HOLD, COMPLETE, CLOSE | Paginated timeline in authoritative sequence order | Recorded and claimed times distinguished; actor and state transition shown; independent page state |
| Private Sales evidence, financial data, hashes and request receipts | Omitted at server projection | No expand, export, print or hidden-column escape |
| Sales origin identifiers | No extra Sales UI in this slice | Reference remains independently protected in API; quotation UI has separate inactive gates |
| Assignment/start/hold/complete/close and bulk actions | Not applicable | Read-only delivery; no write APIs or controls authorised |

Responsible filtering accepts an exact employee id, visible in assignment detail.
A searchable company employee directory is deliberately not added: the existing
administration endpoint requires its own duty, and `job.view` must not grant that
directory. No first-page list is presented as a complete employee picker.

Loading uses shared skeletons; empty/filtered-empty, failed/retry, missing and denied
states are explicit. Shared semantic tokens, mobile cards, RecordSheet focus/escape,
keyboard row opening, light/dark axe scans and phone overflow checks cover the screens.
No new business write, migration, grant, seed activation or pricing capability is
introduced. Test fixtures exercise the inactive and active read contracts without
changing real company permissions.

## Related

- [Review Job progress](../journeys/review-job-progress.md)

- [Job execution lifecycle](job-execution-lifecycle.md)
- [Jobs module](../../backend/app/Modules/Jobs/README.md)
- [API conventions](../architecture/api-conventions.md)
- [Authorisation](../architecture/authorization.md)
- [Feature completion checklist](../architecture/feature-completion-checklist.md)
