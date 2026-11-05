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
