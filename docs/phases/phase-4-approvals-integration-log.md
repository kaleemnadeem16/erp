# Phase 4 — approvals integration for quotations: working log

Owner-authorised task (issue #76): connect quotations to the shared Approvals module so an authorised approver can approve or reject a draft's recorded need for approval, after which
readiness and issue succeed. Worked in an own worktree (`/home/kaleem/projects/erp-approvals`, branch `work/approvals`) and pushed to `develop` with `git push origin HEAD:develop`
after merging `origin/develop`, because other agents had uncommitted work in the shared folder. Decision record: [ADR 0158](../decisions/0158-approve-quotation-concessions-through-the-shared-approvals-engine.md).

## Ground rules recorded
- Shared-folder safety: nothing uncommitted by anyone else was touched; only explicit paths staged.
- Tests only on `erp_testing_approvals_approvals` (focused runs); never against the dev database `erp`. Database dropped when done.
- STAGED duties stay STAGED: `sales.quotation.approve` is new STAGED, no activation, no grant, not in the dev preview.

## Owner questions (recommended defaults applied, not guessed silently)
1. **Who may approve and at what discount level (thresholds)?** Default applied: any holder of `sales.quotation.approve` (record policy OWN/ALL, independent view) that the company's active
   approval policy step names, never the preparer or requester. **No discount-size threshold** is routed: it needs a base-currency measure of the concession and the owner's numbers.
   Recommended: decide thresholds (for example "up to X percent: sales lead; above: director") and let a follow-up route a `reduction_percent` fact.
2. **Does approval expire?** Default applied: no automatic expiry; an approval lasts exactly as long as the draft is unchanged. The engine supports step deadlines
   (`deadline_at`, expire-request action) if the owner wants one; recommended: none for drafts because any edit already voids it and drafts expire with their validity date.
3. **Can an approval be delegated?** Default applied: no. Recommended: keep it off until the owner names who may stand in for whom.

## Slices

### Slice 1 — backend, HTTP surface and generated client
Files: `Approvals/Support/RecordScopedApprovalSubject.php` + `ApprovalEligibility` (optional record-policy hook, one eligibility for every route); Sales `Support/Quotation/Approval/*`
(subject `QUOTATION_REVISION`, content fingerprint `QAF.1`, derived standing, outcome, engine-refusal translation); Actions `RequestQuotationApproval`, `DecideQuotationApproval`,
`WithdrawQuotationApproval`, `ReadQuotationApproval`; `QuotationController`-style `QuotationApprovalController`, four Requests, two Resources, four routes
(`GET|POST quotations/{revision}/approval`, `POST …/approval/decision`, `POST …/approval/withdrawal`); `QuotationIssueAssessor`/`IssueAssessment`/`IssueQuotation` consume the approval;
migration `2026_11_20_000000_add_quotation_issue_approval_link` (issue names its approval; populated rollback refuses); duty `sales.quotation.approve` SPECIFIED to STAGED.
Edits that end an approval: `UpdateQuotationDraft`, `CancelQuotationDraft` call `InvalidateApprovals::forSubject`.

Problems and rules applied:
- `PreviewQuotationInternalUseTest` pins the unguarded preview to one caller (the assessor). My first draft of `RequestQuotationApproval` called it; fixed by asking the guarded
  readiness Action for the live need instead (rule: the assessor is reached only through guarded Actions). Test not changed.
- Narrow pinned-assertion updates (the feature deliberately lifts "approve is SPECIFIED"): `SalesDutyManifestTest` (Specified 30 to 29, approve joins the STAGED list),
  `SalesDutyRegistrationTest` (three new URIs and their methods), `QuotationAccessSurfaceCoverageTest` (four surfaces, three governed writers).
- The engine hashes only facts plus base amount; the exact-draft binding therefore rides in the routed facts (`input_generation`, `content_fingerprint`), not in a second mechanism.
- Decision-time stale evidence: refused as `QUOTATION_DRAFT_STALE` / `APPROVAL_STALE` without writing (the transaction would roll an invalidation back); the invalidation is written by the
  edit that caused it.

Verified: `QuotationApprovalApiTest` (13), `tests/Feature/Modules/Sales` (511), `tests/Unit/Modules/Sales`, `tests/Architecture`, `--filter=Approval` (160), Pint, PHPStan (no errors),
`migrate-cycle.sh`, codegen regenerated (`QUOTATION_REVISION` now appears in the Approvals subject-type enums, which is why three existing generated enum files changed).

### Slice 2 — frontend
`quotation-approval-contract.ts` (zod schemas, plain wording, mocks), `quotation-approval-access.ts` (prepare/approve each with view, behind the existing gate: production stays OFF),
`quotation-approval-screen.tsx` (status badge, decision history, request / approve / reject / withdraw with reason dialogs, 44px targets, fixed refusal wording by reason code, one
`IntentKey` per intent so an uncertain failure replays), route `/quotations/:revision/approval` (`screen-access.ts`, `router.tsx`, `QuotationApprovalRoute`), link from the detail and readiness
pages, `APPROVAL_PENDING` / `APPROVAL_REJECTED` wording. The generated `quotation-approval` module was missing from `core-api/src/index.ts` (hand-maintained barrel): added.
Verified: three `tsc --noEmit`, `vite build`, the whole Playwright `unit` project (126, including the new `quotation-approval.unit.spec.ts`).

### Slice 3 — demo data
`QuotationApprovalsDemoSeeder` (after `QuotationIssuingDemoSeeder`; local preview only like it): approval mode REQUIRED and one policy only when absent; demo approver persona
`demo-quote-approver@erp.x1techs.com` (view + approve permission rows, nothing added to the dev preview); scenarios WAITING, APPROVED, ISSUED (names its approval), REJECTED (reason), WITHDRAWN,
CHANGED-after-approval. Tested by `QuotationApprovalsDemoSeederTest` (skips outside local preview; in local preview builds every state, rerun adds nothing, duties back to STAGED).
Problem: a 25% discount is both above the limit (needs approval) and fails the company's 10% mandatory reduction control; approval does not lift a failed control, so the REJECTED scenario is the
only discount one and the issuable scenarios use small overrides. Rule: controls and approval are independent (ADR 0158 §6).

## Remaining work
- Owner decisions: thresholds by discount size (needs a base-currency concession measure routed as an engine fact), expiry, delegation, the holder recipe for `sales.quotation.approve`, and whether
  the local dev preview should also switch on `approve` (and discount/override) so the browser can decide as the demo approver.
- Notifications to approvers (deliberately deferred ERP-wide, Q33).
- A first-class "approvals waiting on me" entry on the Quotes list (the generic `/approvals` inbox lists the request only if the approver also passes the record policy; it does, but has no quotation deep link).
- Approval of a replacement revision (`IssueQuotationReplacement` consumes the same assessor, so a replacement with concessions needs its own approval; not exercised by a dedicated test).
- Browser (Playwright e2e) coverage with a signed-in approver, once the owner decides the preview question.

## Discount-authority foundation — owner specification, 2026-11-06 (#86)

The original threshold/expiry questions above describe the historical ADR 0158 integration. The
owner has now selected [ADR 0163](../decisions/0163-govern-quotation-discount-authority-with-independent-reference-evidence.md)
and the [full backend specification](../domain/quotation-discount-authority.md): independent
governed references, precise percentage/base-amount and line/quotation limits, effective employee
authority, strict quotation-only routing, configurable 14-calendar-day expiry capped by validity,
shadow evaluation before explicit company activation. Final-net overrides and undeclared reductions
still need independent approval; no delegation/grants/activation. The
[lock-order ADR 0164](../decisions/0164-order-discount-authority-locks-before-quotation-and-approval-records.md)
is proposed for coordinator review. Four ordered implementation commits/stages follow only after
the preceding stage is green. UI is a contract handoff; one new demo seeder is permitted, existing
Sales seeders are reserved. This entry records specification only, not delivered authority.

## Discount-authority stage 1 — internal reference foundation

Implemented governed reference versions, independent approval, exact-intent configuration receipts,
family/economic-source bindings and pinned-FX validation. Migration:
`2026_12_03_000000_create_sales_governed_price_references`; no dev database migration was run.
New duty `sales.pricing.reference.approve` stays STAGED, unregistered and ungranted. No new route,
ordinary quotation behaviour, grant, activation or UI. Tests exercise missing/unapproved references,
independence, edits/splits/revisions, ambiguity, dates, tenancy, replay/revocation, raw immutability,
precision and pinned FX. [Delivery evidence](../domain/quotation-discount-authority.md#stage-1-delivery-evidence).

## Discount-authority stage 2 — internal policies, assignments and evaluation

Company-scoped immutable policies/bands, activation history, employee/role/unit assignments,
revocations and mode events are implemented. Exact pre-tax evaluations retain line/document inputs,
allocation, original actors, governed scope anchors and pinned FX; routine authority checks those
entrants' current duties and assignments. No ordinary issue clearance, new route, grant or UI.
Migration `2026_12_03_010000_create_sales_discount_authority_configuration` passed its isolated
cycle. Sales/Company regression: 696 passed, one existing docs-path skip; focused reference/policy/FX/evidence: 45 passed;
whole-project PHPStan passed. Required schema ratchets currently fail on the other owner's
Purchasing FK introduced in `3eb5d9b8` (#90); reported with reproduction on #90 and #86, left
untouched. Historical gate: fixed by `253332eb`; the tenant-FK ratchet passed on 2026-11-09. [Details](../domain/quotation-discount-authority.md#stage-2-internal-configuration-and-evaluation).

## 2026-11-09 — strict discount routing and initial integration checkpoint

The tenant-FK gate is resolved. Opt-in engine transaction, strict policy selection and protected
field hooks are implemented for Sales, with evaluated concession routing, named approver-band
checks, fourteen-day/quotation-validity expiry, and decision/issue revalidation. Quotation writes
append authority evaluations; issue pins its evaluation through a company-composite FK. No duty
activation or holder grant. ADR 0164 contains the concrete lock/barrier proposal for owner review.
Six real two-session tests passed, including both decision/configuration orderings and live
suspension, permission, assignment and source changes. Configuration APIs, the final demo and
complete feature close-out remain outstanding; see the canonical [checkpoint](../domain/quotation-discount-authority.md#routing-and-lifecycle-integration-checkpoint-2026-11-09).

## Related

- [Domain specification](../domain/quotation-discount-authority.md)
- [Phase index](README.md)
