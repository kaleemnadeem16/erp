# Phase 4 — issuing quotations: working log

Running log of the agent finishing "Issuing quotations" (issue #76, G-A1/G-A2 B1–B3 on develop). Owner direction: finish backend, web routes, generated client, frontend, tests, docs and demo data.
Other agents share `QuotationAccess`, the route registry, the generated client and the quotation screens; this log lists every shared file touched and why. Newest entries last.

## Ground rules recorded
- Dirty tree at start (not mine, left untouched): `CompanyAccessFence*`, migration `2026_11_10_000000_bound_company_access_barrier_waits`, `backend/tools/`, `docker/backend/measure-access-fence.sh`. Owner said: proceed, leave alone.
- Owner decision (2026-11-03): **a local-only dev issue grant for the demo seeders is allowed** — behind the same `APP_ENV=local` switch, never in tests of production behaviour, no production effect. STAGED duties stay STAGED everywhere else.
- Local note: `backend/storage/api-docs/openapi.json` was a stale, root-owned, gitignored file (Oct 17) that blocked `codegen.sh`; moved aside to `openapi.stale-oct17.json` (gitignored, can be deleted by the owner).

## Slice 1/2 — readiness audit and issued lifecycle read model
Findings (audit of `QuotationIssueAssessor` + providers against tests):
- Backend readiness blockers were already accurate and covered: RECIPIENT_REQUIRED/NOT_ACTIVE, PRICING_DATE_REQUIRED, VALID_THROUGH_REQUIRED/PAST, NO_LINES, line price/net/FX/rounding codes, TAX_* (FR3), CONTROLS_* (FR4), stale generation, already issued, not editable (cancelled), staged/forbidden, OWN-limited hidden as 404.
- Gap: the issued read and list gave no way to tell AVAILABLE / EXPIRED / WITHDRAWN / REPLACED, and the list had no official number.
- Gap (UI): many emitted codes (TAX_*, CONTROL*, CONTROLS_*) had no plain-language label (handled in the frontend slice).
- Approval-needed flag from the pricing agent has not landed on develop: nothing to wire yet; the readiness contract is code-based so a new blocker code will flow through (listed as remaining work).

Changes (all additive, read-only):
- `ReadIssuedQuotation` + `QuotationIssuedResource`: new `availability` (derived by the existing `QuotationAvailability`, never stored; database clock).
- `ListQuotations` + `QuotationSummaryResource`: new `official_number` and `availability`. The `state` filter already supported DRAFT / CANCELLED_DRAFT / ISSUED.
- Test `QuotationListApiTest` key allowlist extended by those two keys (still an exact pin).
- New test `QuotationIssuedLifecycleReadTest` (available → withdrawn, list fields + ISSUED filter, no protected words, cancelled draft readiness refusal, OWN-limited preparer gets the same 404 as unknown).
- Generated client regenerated (`quotationIssuedResource`, `quotationSummaryResource`, one new model file).

Decision: withdraw/replace have Actions (QL4/QL5) but **no HTTP routes or screens** and are not part of this milestone's accepted scope; listed under remaining work (needs a route + duty review). QL6 revision history and B5 customer acceptance are out of scope.
