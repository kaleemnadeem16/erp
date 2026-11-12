# 0182 — Reapprove unissued purchase orders by materiality when a stricter policy takes effect

- **Status:** proposed (design only; owner questions open; nothing implemented)
- **Date:** 2026-11-10
- **Affects:** Approvals policy activation and request model; Purchasing issue/submit gate; PO screen and approvals inbox
- **Issue:** #106 (matrix row 18)

## Context

A purchase order is approved while it is still a `DRAFT`, then issued later (ISSUED assigns the number).
Between those two moments a company may activate a stricter approval policy version (ADR 0070 §3:
`ActivateApprovalPolicy` supersedes the active row of the same `subject_type` + `code`).

Today:

- an `ApprovalRequest` pins the `policy_id` (the version row) it was routed under and a `facts_hash`
  over the routing facts and the base amount;
- `RequireApproval` at issue time compares only `facts_hash` and the review-provider fingerprint. It
  never asks whether the pinned version is still the one that would route this order today;
- so an order approved under the old, looser policy can be issued under a newer, stricter one with no
  further approval. That is correct for history (ADR 0070 §3: "requests already routed keep the version
  they were routed under") but it is a control gap for orders that have **not yet become official**.

Re-approving every approved-but-unissued order on every policy change would be noisy and would punish
changes that do not matter (a renamed step, a changed expiry, a threshold that does not touch this
order). The owner asked for a materiality test, not a blanket invalidation. Issued, closed and cancelled
orders are never touched: their approval is a fact about what was decided at the time.

## Decision

### 1. What is material

A new policy version is **material for a given order** when, comparing the approval the order holds
(its approved request's pinned version and routing snapshot) with the **resolution the order would get
today** (`ResolveApprovalPolicy` on the order's current routing), any of these is true:

| # | Test | Why |
|---|---|---|
| M1 | The order now needs approval but the old one did not (no policy matched, or mode was OPTIONAL and now REQUIRED). | New control. |
| M2 | A step exists today that the held approval has no equivalent of: a new stage, a higher `required_approvals`, a different/stricter `permission`, or `use_authority_limit` turned on. | Stricter authority or count. |
| M3 | The authority level that signed is no longer enough. For each decision on the held request, re-evaluate the approver's authority limit against the order's base amount under the current step definition; if the signer would not qualify today, material. | Authority-level change. |
| M4 | `allow_submitter` turned off while the submitter signed a step. | Stricter separation of duties. |
| M5 | The matched policy's condition thresholds now bring this order's `amount_base` (or supplier/currency fact) into a different, stricter band. | Value threshold crossed. |

Not material (the held approval stays valid): a name/priority/description change; a looser or equal
change; a longer `expires_after_hours`; an escalation permission change; any change to a policy that no
longer or never matched this order's facts; a version that resolves to the same step set. Comparison is
on **effective step requirements**, not on version numbers, so a re-saved identical policy is never
material. A `material` verdict is **never inferred from a looser change**: the test is one-way.

Value for M5/M3 is always the order's **pinned base amount** (the existing routing amount, at the order's
own pinned FX rate, ADR 0165), never a re-converted amount. Thresholds are compared in the company base
currency, so a rate move cannot trigger reapproval.

The check is a pure function `PolicyMaterialityJudge(heldRequest, currentResolution) -> {material:bool,
reasons:[code...]}` living in Approvals, deterministic and unit-testable, returning machine reason codes
(`NEW_CONTROL`, `MORE_STEPS`, `MORE_APPROVERS`, `STRICTER_PERMISSION`, `AUTHORITY_INSUFFICIENT`,
`SEPARATION_TIGHTENED`, `THRESHOLD_BAND`).

### 2. Which orders are in scope

Exactly the purchase orders that are `DRAFT`, have no `inventory_cutover_id` (migrated cutover orders were
decided in the legacy system), and whose **latest** request is `APPROVED`. Excluded: pending requests
(they are still being decided; see §3 for how a pending request reroutes), `ISSUED`/`CLOSED`/`CANCELLED`
orders, orders with no approval, and orders whose latest request is rejected/withdrawn/expired/invalidated
(they already need a new submission, which will route under the current policy).

### 3. How reapproval is triggered and queued

Two layers, so there is no sweep that can be skipped:

1. **Gate at issue (authoritative).** `RequireApproval` gains the materiality check after the existing
   hash check. If material, it invalidates the approved request with closing reason
   `POLICY_CHANGED:<codes>` (new status value is **not** added; reuse `INVALIDATED`, with a structured
   `closing_reason` code) and throws the existing "needs approval first" refusal, now carrying
   `reason_code` `APPROVAL_REAPPROVAL_REQUIRED`. Nothing relies on a background job having run.
2. **Flag at activation (visibility).** `ActivateApprovalPolicy`, inside its transaction, enqueues
   one `ReviewUnissuedPurchaseOrders` job per company after commit (not inline: could be thousands of
   rows). The job pages through in-scope orders, runs the judge, and for each material one records an
   append-only `approval_reapproval_flags` row (order, old request id, old/new policy ids, reason
   codes, activated-by, flagged_at). It does **not** invalidate and does **not** auto-submit. The flag is
   what the UI shows and what the owner reports count; the gate in layer 1 is what enforces.

The order then follows the ordinary path: the buyer resubmits (`SubmitPurchaseOrder`), the engine routes
under the current version, a **new** request is created and the flag is closed by it (`resolved_by_request_id`).
Auto-resubmission is rejected (see Alternatives): only a person may state that the order is still wanted.

A **pending** request is not re-routed in place; its pinned version stays as submitted (consistent with
ADR 0070). If it is approved and the policy was material meanwhile, layer 1 catches it at issue.

### 4. What the user sees

- **PO detail and list (buyer/issuer):** an `approval` block already exists on the order response; add
  `reapproval: { required: true, reason_codes, reasons[] (plain English), policy_changed_on }`. A banner:
  "A stricter approval policy took effect on <date>. This order must be approved again before it can be
  issued: <reason>." Primary action becomes "Submit for approval"; **Issue is disabled** with the same
  reason, not hidden (authorization R-rules: disabled with a reason).
- **List filter/column:** "Needs reapproval" chip, so a buyer can see all affected orders at once.
- **Approvals inbox:** the new request shows "Reapproval" and links the superseded decision history;
  the approver sees the previous approvers and what changed.
- **Admin on activation:** the activation response and policy screen show "N unissued purchase orders will
  need reapproval" (computed by a dry-run of the same judge, read-only), so the activator is not surprised.
- Reason text never exposes thresholds or amounts to users without commercial-evidence access
  (`PurchasingCommercialAccess`); those users see the code-level reason only.

### 5. Audit trail

Nothing is rewritten. Old request, steps and decisions remain; the old request moves to `INVALIDATED`
with a structured closing reason. Add append-only `approval_reapproval_flags` (above) and write one
activity-log/audit entry per flag and per gate-invalidation, naming the policy versions, reason codes and
the judge version. The new request references the superseded one (`supersedes_request_id`, nullable),
giving a chain from the original approval to the current one. Judge inputs (old step set, new step set,
authority limits consulted) are snapshotted onto the flag so the verdict is reproducible later even if
authority rows change.

### 6. Race and lock considerations

Follow the order published in ADR 0164: security fence, company authority head, then PO header, then
approval request/steps. Specifically:

- `ActivateApprovalPolicy` already runs under the authority writer barrier; the post-commit job takes
  the fence/head first per batch, then each order row `FOR UPDATE` in ascending id, never the reverse.
- The gate in `RequireApproval` runs inside `IssuePurchaseOrder`'s transaction after the PO header lock
  and re-resolves the policy under the head, so activation and issue serialise: either issue sees the new
  version (and refuses) or activation commits after issue (the order is already ISSUED and out of scope).
- The job re-reads each order's status inside its lock and skips anything issued meanwhile. Flags are
  unique on (`purchase_order_id`, `new_policy_id`, `old_request_id`), so reruns are idempotent.
- A buyer submitting concurrently with the job is safe: submission supersedes the old request under the
  order lock; the job skips orders whose latest request is no longer `APPROVED`.
- Sales' strict-selection generation is unchanged; Purchasing keeps its non-strict selection semantics.

### 7. Migration and rollout

- Additive migration only: `approval_reapproval_flags` table (company-scoped, indexed by order and
  by open/closed), nullable `supersedes_request_id`. No backfill of historical approvals, no
  data change to existing requests.
- Ship dark: a per-subject setting `reapproval_on_stricter_policy` (default **off**) so merging this
  changes no behaviour (owner review: no extra feature activation). When off, `RequireApproval` is
  unchanged and no jobs run.
- Turn on per company after the owner answers the questions below. First enablement does **not**
  retro-flag orders approved before the setting existed unless the owner chooses "check on next issue" (the
  default: the gate is evaluated lazily, only when someone tries to issue).
- Feature is PURCHASE_ORDER only; the judge is subject-agnostic but other subjects opt in separately.

### 8. Tests required

- **Unit, judge:** one case per reason code; each non-material change (rename, priority, looser
  threshold, longer expiry, identical re-save) stays not material; M3 with revoked/lowered authority
  limit; FX-independence (rate moves, amount_base pinned, not material).
- **Feature, gate:** approved draft + material new version cannot be issued
  (`APPROVAL_REAPPROVAL_REQUIRED`), resubmission then issue succeeds; non-material version issues
  unchanged; setting off = legacy behaviour; cutover orders exempt; issued/closed/cancelled never flagged.
- **Feature, job:** flags only material in-scope orders; idempotent rerun; skips orders issued mid-run;
  pages large sets; company isolation (cross-company orders never flagged).
- **Concurrency (separate real connections):** activate vs issue in both orderings; activate vs submit;
  job vs issue. Prove no deadlock with the 0164 order.
- **API/UI:** `reapproval` block shape and codegen; disabled Issue with reason; list chip; dry-run count;
  commercial-evidence redaction; permission checklist (no new surface authenticated-only).
- **Audit:** old decisions untouched; chain `supersedes_request_id`; flag and judge snapshot written.

## Consequences

- **Positive:** a stricter policy actually binds orders that are not yet official, with no mass
  invalidation; history stays intact; enforcement does not depend on a background job.
- **Negative:** extra judge logic to maintain alongside the policy model (any new step attribute must
  state whether tightening it is material); lazy gate means a buyer may discover reapproval at issue time
  unless the flag job has run; the job adds an async path to test.
- **Requires:** owner answers below before implementation; coordinator review of the shared
  `RequireApproval`/`ActivateApprovalPolicy` edits (other consumers must not change behaviour).

## Alternatives considered

**Invalidate all unissued approvals on every activation** — rejected by the owner brief; noisy, and
punishes changes that do not affect the order. **Never reapprove (status quo)** — leaves the control gap.
**Reapprove on any version change of a matched policy** — version numbers say nothing about strictness.
**Auto-resubmit to the new policy** — a system cannot assert the buyer still wants the order, and it would
make the submitter the system actor, defeating separation of duties. **Gate only, no flag job** — correct
but users learn at issue time and admins cannot see the blast radius. **Job only, no gate** — a missed or
failed run silently lets an old approval through. **Re-convert amounts at activation** — would make FX
movement trigger reapproval; contradicts ADR 0165 pinned-rate rule. **Store a copy of the whole policy
on the request** — duplicates data; the pinned version row is already immutable.

## Open owner questions (recommended default first)

1. **Which tightenings are material?** Default: M1 to M5 as above. Alternative: threshold-only (M5).
2. **Authority re-check (M3):** re-test signers against today's authority limits, or only the policy
   text? Default: policy text and the authority limit as of the **decision** time (do not penalise a
   signer whose limit lapsed later); only a policy that raises requirements counts.
3. **Enforcement:** gate at issue plus flag job. Default: both. Alternative: gate only.
4. **Pending requests:** leave as routed (default) or restart under the new version.
5. **Dry-run and notification:** default show the count to the activator and notify the order's
   submitter; no email to approvers until resubmitted.
6. **Time window:** should orders older than N days be reapproved regardless? Default: no (out of scope).
7. **Rollout:** default off; enable per company after review; lazy evaluation, no retro-flag.
8. **Who may clear a flag without resubmitting?** Default: nobody; resubmission is the only path.
9. **Other subjects (quotations, supplier invoices):** default out of scope, separate decisions.

## Owner conditions 2026-11-12 (design only)

Any implementation must satisfy all of these before the feature can be reviewed:

1. Company-specific approval policy, thresholds, effective dates and approval levels are configured per company (see [0185](0185-configure-company-business-values-and-fix-technical-invariants.md)).
2. Tie handling (an amount exactly at a threshold, equal levels) is resolved without weakening the behaviour of existing consumers.
3. Purchasing and Inventory authority thresholds are defined separately.
4. The conflicting authority-recheck semantics (open question 2 versus the signer-authority behaviour of existing consumers) are resolved explicitly.
5. The shared lock order is proved (with [0164](0164-order-discount-authority-locks-before-quotation-and-approval-records.md)), not assumed.
6. Issued-document history is preserved; there is no mass invalidation.
7. The reapproval feature stays OFF until reviewed and activated per company.
8. This is a post-Phase-4 milestone.

## Research basis

Inspected on local `develop`: `ActivateApprovalPolicy`, `ApprovalPolicy` (versioned rows, same `code`),
`ResolveApprovalPolicy`, `RequireApproval`, `InvalidateApprovals`, `ApprovalRouting::hash`,
`SubmitPurchaseOrder`, `IssuePurchaseOrder`, `PurchaseOrderApprovalSubject`; ADRs 0070, 0139, 0164,
0165; [approval-workflows.md](../architecture/approval-workflows.md) and [approvals.md](../domain/approvals.md).
