[← Architecture](README.md)

# Legacy approval workflows vs the shared engine — audit

- **Status:** findings and recommendation, 2026-09-30. **Nothing has been migrated or changed.** The decision belongs to
  the owner; if a migration is chosen it gets its own ADR.
- **Compared against:** the shared approval engine ([ADR 0070](../decisions/0070-shared-configurable-approval-engine.md),
  [approvals.md](../domain/approvals.md)).
- **Audited:** negative-stock overrides ([ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md)), backdated-receipt
  recalculation ([ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)) and inventory cutover
  ([ADR 0036](../decisions/0036-controlled-inventory-cutover.md)), including its negative-opening exceptions.

The goal is one strong shared foundation *where it fits*, while genuine domain rules stay with their module. A workflow is only
worth moving if the move removes real duplication or unlocks something the company wants, without losing a domain rule.

## What the engine is, and is not

The engine answers one question **before** an action: *do the required approvals exist for this document as it is now?* Its
strengths are configuration (policies, amount bands, N distinct approvers, stages, limits, delegation), pinned evidence, and
voiding an approval when the document changes. It deliberately does **not** model: control *after* the act, approvals that
lapse after they are granted, regeneration of a frozen impact in place, or a domain minimum that configuration may never lower.
Each legacy workflow below leans on at least one of those.

## 1. Negative-stock overrides (ADR 0037)

**What it does.** A request for a shortage is *derived* by the server (never client-declared) from live stock, and is an
**authority token** the stock-posting boundary consumes: `IssuePoster::authoriseShortage` checks identity (item, location, batch,
source), that the current shortage does not exceed the authorised one, that a prior-approval request was approved, that the
token is single-use, and that an existing open shortage is settled first. The company's authorisation mode is snapshotted onto each
request:

| Mode | Behaviour |
|---|---|
| `PRIOR_APPROVAL` | A *different* user with `inventory.negative-stock.approve` approves or rejects (notes required) **before** the movement posts. |
| `IMMEDIATE_WITH_REVIEW` | The requester's own `…override` capability authorises the movement **immediately**; an independent review (`…review`; self-review only if the company allowed it, snapshotted as `allow_self_review`) is due within an SLA (`review_due_at`) **after** the fact. |

Requests and decisions are immutable at the database; requests are idempotent per source document; shortages reference the request.

| Question | Finding |
|---|---|
| Maps directly | `PRIOR_APPROVAL`: one step, distinct approver, reason required, immutable decision, maker/checker. `allow_self_review` ≈ a step's `allow_submitter`. The review SLA ≈ an overdue deadline. |
| Does not map cleanly | `IMMEDIATE_WITH_REVIEW` is **not an approval**: it is control *after* the action. The engine gates before (`RequireApproval`) and has no "act now, review later, flag overdue" semantic. The token's consumption rules (identity, single use, no deepening) are stock-posting invariants, not approval rules. |
| Would migration simplify? | Barely. Two small Actions (≈100 lines) and a model; the complexity lives in the posting boundary and would stay. Moving `PRIOR_APPROVAL` alone would leave two mechanisms for one workflow. |
| What would the company gain? | Real gains exist only as *new* capability: several approvers or an amount/quantity band for a large shortage (today: exactly one approver). |
| History/data | Historic requests, approvals and reviews are referenced by shortages and settlement records and guarded by triggers. Backfilling engine requests would mean inventing a policy version and decisions for history. **Avoid.** |

**Recommendation — keep the domain workflow; optionally add the engine *additively* later.** If a company wants bands or
multiple approvers on `PRIOR_APPROVAL`, register a `NEGATIVE_STOCK_OVERRIDE` subject (facts: movement class, item, shortage
quantity and value) and, only when the company sets that subject's mode, have the request also pass the engine and the posting
check additionally call `RequireApproval`. With the mode `NONE` (default) nothing changes and no history is touched.
`IMMEDIATE_WITH_REVIEW` stays entirely in Inventory.

## 2. Backdated-receipt recalculation (ADR 0040)

**What it does.** A request builds an immutable **impact version** (economic figures, a risk class, and a fingerprint of the
history and accounting periods it rests on). `DecideRecalculationApproval` then:

- requires the requester to differ from every approver, and `inventory.recalculation.approve`;
- needs **one** approver for `NORMAL_RISK` and **two** distinct for `HIGH_RISK` (a closed accounting period overlapping the window is
  a hard trigger; an optional company threshold can only *raise* the bar — it can never disable a hard trigger); the second slot needs
  `…approve-high-risk`;
- gives **each** approval its own `expires_at` (a bounded execution window), allows the same approver to *renew* a lapsed slot and a
  different eligible person to *reroute* into it, and refuses a second live decision by one person (also a DB trigger);
- on every decision **revalidates**: if the impact, its risk or its dependencies changed it flips the version stale and appends a
  replacement *under the same request* (committing that even though the decision itself is refused), counts post-approval
  invalidations, **pauses regeneration after three**, and requires a reviewer (`…review-regeneration`, plus high-risk authority for
  repeated caps) to resume it;
- lets `ExecuteRecalculation` run only with unexpired approvals on a non-stale version, under the ledger locks.

| Question | Finding |
|---|---|
| Maps directly | Distinct approvers counted per version; requester exclusion; reason/notes; append-only decisions; a step whose eligibility is a higher capability (`approve-high-risk`); "the document changed, so the approval is void" (the engine's facts hash). |
| Does not map cleanly | **(a)** A *floor the company cannot configure away* (closed period ⇒ two approvers). Engine policies are company-configurable; using them for the count would let an administrator lower a safeguard ADR 0040 makes non-disableable. **(b)** **Decision-level validity windows** (an approval that lapses after being granted, with renewal/rerouting). The engine's expiry is a *step deadline before* a decision, never the life of a granted one. **(c)** **Regenerate-in-place**: a stale version yields a new unapproved version under the same request, with a regeneration cap, an escalation ladder and a resume review. The engine's answer to change is to void and resubmit. **(d)** The approval is tied to an *execution* under ledger locks and a database guard trigger that serialises approval inserts. |
| Would migration simplify? | No. It would delete little code (~150-line Action plus the classifier and limit classes) and would need the engine to grow features (b) and (c) that only this workflow uses. |
| History/data | Impact versions, approvals, executions, regeneration reviews and the guard triggers form a tightly coupled, immutable audit chain used by replay and correction records. Migrating it risks breaking exactly what ADR 0040 exists to protect. |

**Recommendation — keep it in Inventory, permanently.** It is the clearest example of a genuinely domain-specific control. The
fixed 1-or-2 count is a *floor*, so the only future integration worth considering is **"domain floor plus engine extension"**: the
domain still computes the minimum approvers, and a company policy may require *more* (never fewer). Not needed now; it can be
designed if a company asks for a third approver.

## 3. Inventory cutover (ADR 0036) and its negative-opening exceptions

**What it does.** A cutover is a **one-off** migration event (only one non-failed cutover per company). `ApproveInventoryCutover`:
one approver with `inventory.cutover.approve`, who must differ from **both** the creator **and** the validator, moving `VALIDATED →
APPROVED`; commit and finalise follow with their own capabilities. A **negative-opening exception** is requested with server-derived
evidence (from the staged facts), a provisional cost, a reason, an owner, a resolution plan and a target date (30 days unless
explained), and approved by an independent actor with its own capability; commit refuses a negative fact with no approved exception.

| Question | Finding |
|---|---|
| Maps directly | Both are single-step, maker/checker approvals with a reason and an immutable actor and time. |
| Does not map cleanly | Two-party exclusion (creator **and** validator) where the engine excludes only the submitter; approval of an exception is really *acceptance of evidence and a plan* with domain fields (owner, target date). The approval is one gate inside a staged pipeline (create → stage → validate → approve → commit → finalise) whose states are the domain's. |
| Would migration simplify? | Almost nothing: ≈75 lines across two Actions, used once per company. A generic policy screen adds no value to something that happens once. |
| What would the company gain? | Perhaps several approvers for a very large opening balance — a rare, deliberate choice. |
| History/data | Small (one cutover per company) and easy to leave alone; but there is no benefit to rewriting the record of a company's opening state. |

**Recommendation — keep as is.** If a company ever needs N approvers or bands on cutover, it is an *additive* engine subject
(`INVENTORY_CUTOVER`), exactly like negative stock, not a migration.

## Overall

| Workflow | Approach | Why |
|---|---|---|
| Negative-stock overrides | **Keep; optionally integrate additively later** (`PRIOR_APPROVAL` only) | `IMMEDIATE_WITH_REVIEW` is not an approval; the token consumption is a posting rule; the only real gain is new capability (N approvers/bands). |
| Recalculation | **Keep in Inventory; consider "floor + extension" only if asked** | Non-configurable floor, decision-level expiry, regenerate-in-place and execution-window semantics have no engine analogue and are its point. |
| Cutover and negative openings | **Keep** | One-off, low volume, staged domain pipeline; migration would be churn. |

Consistency is not by itself a reason to migrate. The shared engine's value is *configurable* approvals for documents companies
customise (purchase orders, quotations, discounts, supplier invoices, payments). The three legacy workflows are *fixed safety
controls on stock integrity*, where configurability is a risk as much as a benefit.

## Gaps the engine could close *if* a second workflow needs them (not built)

1. **Control after the act** (`review after` with an SLA) — needed by `IMMEDIATE_WITH_REVIEW`.
2. **A domain floor** a company policy can only add to.
3. **Decision-level validity windows** with renewal.
4. **Regenerate-in-place** with a cap and a resume review.
5. **Consumption tokens** (an approval consumed once by an action).

None is justified by the purchase-order or variance consumers, so none is built. Add one only when a real second consumer needs it.

## What to do if a migration is chosen anyway

Record an ADR first; keep the old tables and triggers as the source of truth for history and add engine requests *only for new
documents* (no backfill); gate by the subject's approval mode (`NONE` by default) so behaviour is unchanged until opted in; add
the domain rule as a check that runs in addition to, never instead of, the engine; and prove with tests that every existing
invariant still holds (`RecalculationApprovalAuditTest`, `NegativeStockOverrideWorkflowTest`, the cutover tests).

## Related

- [Approvals](../domain/approvals.md), [Approval workflows](approval-workflows.md), [ADR 0070](../decisions/0070-shared-configurable-approval-engine.md)
- [Stock recalculation](../domain/stock-recalculation.md), [Negative stock](../domain/negative-stock.md), [Inventory cutover](../domain/inventory-cutover.md)
