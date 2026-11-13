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
History exposes the existing read foundation's RELEASE/ASSIGN/START/HOLD/RESOLVE_HOLD
events with labelled claimed occurrence time and actor reference. Completion/closeout read
coverage remains with its separately coordinated lifecycle/query slice. Responses omit commercial amounts, costs, internal hashes, source snapshots,
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
- No UI or browser journey is delivered; screen, phone, accessibility and UI disclosure
  checks are not applicable. Demo activation is excluded; isolated test fixtures supply
  authorised configurations. All duties remain disabled without registration or grants.
- Shared-checkout checks use explicit paths on develop; PHP lint, static analysis, module
  and security/API regression, client drift and frontend compilation are delivery checks.

## Related

- [Job execution lifecycle](job-execution-lifecycle.md)
- [Jobs module](../../backend/app/Modules/Jobs/README.md)
- [API conventions](../architecture/api-conventions.md)
- [Authorisation](../architecture/authorization.md)
- [Feature completion checklist](../architecture/feature-completion-checklist.md)
