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

## Slice 3/4 — frontend completion and list integration (behind the existing gate)
- `quotation-issue-contract.ts`: plain-language wording for **every** code the backend emits (tax FR3 and controls FR4 codes had only a generic by-scope fallback); `availability` in the issued schema (required) with `AVAILABILITY_VIEW` (Issued / Expired / Withdrawn / Replaced badge + note; never says "accepted").
- `quotation-issue-screen.tsx`: issued view shows the availability badge/note and a link "Official PDF and delivery" **only** when the person holds document access; the post-issue confirmation carries the same link. Double-submit/retry safety was already built (IntentKey per revision+generation, dialog button disabled while pending) and is covered by existing specs.
- `quotation-list-*`: official number column, status shows Issued/Expired/Withdrawn/Replaced for issued rows (drafts keep their wording), valid-through column kept, state filter (Draft/Cancelled/Issued) kept.
- `quotation-detail-screen.tsx`: "Issue readiness" and "Official PDF" links, shown only for people whose duties allow those screens (the screens still deny on their own).
- Shared file, small: `components/ui/dialog.tsx` — the Close (X) control was a 16px hit area on every dialog; now a 44px box with the icon in the same place (found by the 390px check, fixed once at the shared level).
- Tests: `quotation-issue-lifecycle.unit.spec.ts` (all emitted blocker codes labelled, availability states, PDF link gating, totals hidden for non-commercial viewers, list columns) and `quotation-issue-phone.unit.spec.ts` (390px no horizontal overflow and ≥44px targets for readiness, confirm dialog, issued record in four availabilities, with the compiled app CSS). Whole `--project=unit` suite: 107 passed.
- Note on "generated hooks only": the issue/list/document screens call the generated client through the existing *gateway* seam (generated functions, responses parsed by zod) so tests can script them; I followed that established pattern rather than rewriting it onto hooks.

## Slice 5 — demo data (local preview only)
- Owner decision: a local-only dev issue grant for the demo seeders. Implemented as the narrowest form: `QuotationIssuingDemoSeeder` runs only when `SalesDevPreview::enabled()` (APP_ENV=local + switch); it switches `issue`/`withdraw` ACTIVE only while it runs (manifest instance restored afterwards, like the other quotation seeders) and grants the Tax configuration duty for the run and revokes it. **`SalesDevPreview::DUTIES` is unchanged**, so in the running local app the `issue` duty is still staged and the Issue button is refused (403) — the owner can *see* issued records, but issuing from the screen locally needs a separate decision (adding `issue` to the preview duties).
- Scenarios (stable keys, rerun adds nothing; test `QuotationIssuingDemoSeederTest`): ready draft, issued, issued-expiring-today, issued-then-withdrawn, cancelled draft, draft blocked by a missing tax declaration (DIRECT_ITEM on an undeclared item → `TAX_CLASSIFICATION_REQUIRED`). A fictitious 10% service tax declaration is built through Tax's own Actions (not a legal rule).
- **"Expired" cannot be seeded honestly:** the Actions refuse a past valid-through and issued records are immutable, so the demo issues one valid through TODAY; it shows Expired from the next local midnight with no scheduler.

## Decisions and conflicts
- **Tax facts binding (owner decision vs landed code).** The owner decided: bind `QuotationTaxFacts` to the declared Tax source, DIRECT_ITEM only, manual/service blocked. Before that could be merged, the tax agent landed `DraftQuotationTaxFacts` (commit 613dd95e/28b827e2), which reads the same declared sources **and** also classifies SERVICE_ITEM lines and MANUAL lines from the seller's per-line versioned declaration. I dropped my own narrower adapter (not committed) to avoid two bindings and a conflict in `SalesServiceProvider`. **Awaiting owner:** accept the broader landed behaviour, or ask the tax agent to narrow it.
- A worktree (`/home/kaleem/projects/erp-issuing`, branch `issuing/issuing`) was used from this point because the shared tree had another agent's uncommitted edits in a file origin/develop also changed, which blocks a merge. Pushes go `HEAD:develop` from there.
- Withdraw/replace have Actions (QL4/QL5) but no routes/screens: not in this milestone's accepted scope.

## Remaining work (size: S ≤ 1 day, M 2–4 days, L ≥ 1 week)
- Withdraw / replace HTTP routes + screens, duty review, activation recipe — M.
- Add `issue` (and `withdraw`) to the local preview duties so the owner can issue from the screen locally — S (needs owner yes).
- QL6 revision history/compare — M. B4/B5 customer decision intake and acceptance (and the real `QuotationAcceptanceState`) — L.
- Browser (stack) e2e of the issue flow against the real backend — S–M.
- Approval engine for `APPROVAL_REQUIRED` (today it only blocks) — L.
- Production gate `QUOTATION_UI_ENABLED` and real holder recipes: owner decisions, untouched.

## Owner review corrections (after CI green on 33713ae5)
- **Decisions recorded:** (1) the broader landed Tax facts (DIRECT_ITEM, SERVICE_ITEM, seller-declared MANUAL) are *conditionally* approved, subject to independent Tax/security verification, evidence, tenant isolation and default-deny — not yet independently verified. (2) `sales.quotation.issue` is now part of the LOCAL preview (`SalesDevPreview::ISSUE_DUTIES`, included in `all()` and so in `sales:dev-grant-quotation`); only under APP_ENV=local + the switch, to explicitly granted logins. Shipped manifest/vocabulary still STAGED; no production grant or activation.
- **Seeder fix:** `QuotationIssuingDemoSeeder` now records the login's *direct* permissions before the run and, in `finally`, revokes exactly those it added (success or failure); before, it compared against role-inherited permissions and left the Sales duties behind. Regression tests: restored exactly after success and after a forced failure (whole run rolls back too). Two old assertions that "issue stays off locally" were updated to the approved behaviour and still assert the shipped manifest keeps issue STAGED.
- **Process note:** earlier pushes went straight to `develop` as the work order instructed; from now on changes wait for independent review and owner approval before the integrator merges.
- **Still open:** real-stack browser E2E (issue, retry safety, issued record, PDF navigation, permission refusals). It needs the shared stack and a seeded database; the work order forbids touching the owner's dev DB/stack, so it is waiting for an explicit go-ahead and an isolated e2e database.
