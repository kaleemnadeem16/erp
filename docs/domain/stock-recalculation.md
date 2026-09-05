[← Domain index](README.md)

# Backdated recalculation and corrections

How backdated stock events are controlled and how their economic differences are corrected,
fixed by [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md).
The core stock model is [stock.md](stock.md); negative-stock workflows are
[negative-stock.md](negative-stock.md).

## Implementation status

This ADR is large; it is being built in phases, and the checklist in
[phase-2-purchasing-stock.md](../phases/phase-2-purchasing-stock.md) stays unticked until a line
is fully true for every valuation method — partial delivery is recorded here and in `STATUS.md`
instead of by checking a box early.

**Built today**: receipt, issue and transfer request/preview/approval workflows across
**all three valuation methods**, within the supported clean-history boundaries below, plus
execution for a narrow slice — AVCO or SPECIFIC receipts with no shortage involvement.

- Issue/transfer proposals now use connected movement replay with derived costs, both transfer
  sides and onward dependencies — see [stock-movement-replay.md](stock-movement-replay.md).
- The backdating horizon (`BackdatingHorizonMode`/`BackdatingHorizonEvaluator`) — company policy
  plus the boundary-evaluation service, method-agnostic.
- `Company.authorised_forward_recalculation_enabled` (the "Live policy" toggle), and
  `RequestStockRecalculation` — a separate, proactive Action a caller uses when they know a
  receipt conflicts with later valuation history, the same relationship
  `RequestNegativeStockOverride` has to `IssuePoster`. `ReceiptPoster` itself is unchanged and
  still hard-blocks the conflict; this doesn't catch that exception. It picks the replayer by the
  item's `valuation_method`: `AvcoCounterfactualReplayer` for AVCO,
  `LayerCounterfactualReplayer` for FIFO/SPECIFIC — both produce the same `ImpactPreview` shape.
- `AvcoCounterfactualReplayer` — a real, computed impact preview, reconstructed from the
  append-only `StockValuationEntry` ledger (no manual/estimated figures accepted). It refuses,
  rather than guesses, whenever the pool had any negative-stock shortage activity in the replay
  window, or was already non-positive at the proposed date — faithfully replaying
  `ShortageSettler`'s provisional-cost rules in a parallel pure form is not yet built.
- `stock_layer_consumptions` — permanently links each `LayerAllocator::allocate()` result to the
  move it served (an issue, a transfer's source side, or an exact-layer receipt reversal),
  FIFO/SPECIFIC only — the fact
  `PostInventoryMovement` previously discarded once `qty_remaining` was decremented. AVCO writes
  none; its replay works from the value ledger instead.
- `LayerCounterfactualReplayer` — the FIFO/SPECIFIC counterpart, built on that ledger. One class
  covers both methods: SPECIFIC is FIFO with exactly one always-available layer of qty 1, so a
  brand-new serial degenerates to "its own value, zero repricing of anything else" without a
  special-cased branch. For FIFO, it reconstructs the layer group's state as of the proposed date,
  inserts the hypothetical layer, then re-walks every later receipt (passthrough) and consumption
  (re-running the real, unmodified `LayerAllocator::allocate()` against the counterfactual queue —
  reusing the production selection algorithm rather than a second copy that could drift). Same
  shortage-window refusal boundary as the AVCO replayer, same reasoning. For these receipt replayers, a transfer's destination
  side remains out of scope — treated as an ordinary outbound consumption from the
  source, no cascade into the destination's own subsequent history.
- `RecalculationRequest` → `RecalculationImpactVersion` (immutable, append-only, one controlled
  `is_stale` false→true transition) → one or more `RecalculationApproval`s (each targets one exact
  version, requester/approver separation is absolute). `avco_pool_id` is set for AVCO and null for
  FIFO/SPECIFIC, which has no pool concept — identified instead by
  `(item, location, batch/serial)`, already on the request. Review SLA and approval TTL are
  persisted deadlines, lazily evaluated from `now()` — no scheduled sweep exists in this codebase
  (a deliberate choice; see `RequestNegativeStockOverride`'s identical `review_due_at` precedent).
  Deciding an approval re-runs the replay (the correct one for the item's method) as a
  revalidation precondition: a changed result marks the current version stale and appends a
  replacement under the same request instead of deciding against stale numbers.
- `RecalculationRiskClassifier` — classifies every impact version, at creation, as `NORMAL_RISK`
  or `HIGH_RISK`; frozen thereafter the same as every other economic column on the version. Two of
  the ADR's named hard triggers are implemented, both readable from state that already exists in
  this codebase: a closed `AccountingPeriod` overlapping the window from the proposed date forward
  (deliberately independent of `BackdatingHorizonEvaluator` — it only ever matters under
  `POSTING_FLOOR`, the one horizon mode that doesn't itself consult period status), and an optional
  bounded company threshold (`recalculation_high_risk_threshold_base_amount`, base/functional
  currency, additive-only — it can raise the bar but never disable a hard trigger). Two more named
  triggers remain unimplemented because their underlying models/policies do not exist: "qualifying finalised downstream effects" has no invoicing or job-costing
  model yet to observe (Phase 4/5/6), and "controlled ordinary-tier self-review" can't fire because
  self-review is not permitted at all — see the next point.
- `DecideRecalculationApproval` enforces requester/approver separation absolutely (still no
  small-company self-review exception — that remains Q13-only, per `RequestNegativeStockOverride`)
  and, per version, requires as many *independent* approvers as `risk_classification` demands: one
  for `NORMAL_RISK`, two for `HIGH_RISK` — counted as distinct `decided_by` among `APPROVED` rows
  (`RecalculationImpactVersion::approvedActorIds()`), making "independent" mean a different person
  than the requester *and* than any *other* approver already holding a slot. A `REJECTED` decision
  is terminal — no further decisions are accepted from anyone once one lands. Per ADR 0040's
  `APPROVAL_TTL_TARGET: EACH_REQUIRED_APPROVAL`, every `APPROVED` decision carries its own
  `expires_at` the moment it's granted, not just whichever one completes the requirement — a
  `HIGH_RISK` version's first approval gets a real execution-window deadline too. Approvals stay
  immutable and append-only, so once an approver's own decision expires they may decide again: a
  same-actor **renewal** appends a fresh row rather than reviving the old one, reuses their
  existing slot (never counts as a new approver, and skips the second-approver
  `inventory.recalculation.approve-high-risk` check, already satisfied when the slot was first
  granted), and is blocked while that approver still holds an unexpired decision (one live decision
  per approver per version at a time). A *different* approver can never claim a slot an expiry
  freed — that's authority-only rerouting, a separate, still-unbuilt mechanism. A DB trigger
  (`guard_recalculation_approval_insert`) enforces the same terminal-rejection, distinct-approver-count
  and one-live-decision-per-actor invariants independently of the Action, the same defense-in-depth
  style as the immutability triggers elsewhere in this workflow.

- `ExecuteRecalculation` posts an approved version's backdated receipt truthfully at its physical
  `moved_at` (a new, additional `StockMove` — original movements are never mutated), then converges
  the AVCO pool's running average to the exact approved `counterfactual_*` figures via a second
  `VALUATION_ADJUSTMENT` valuation entry alongside the receipt's own `DESTINATION_IN` entry. That
  convergence *is* the correction for AVCO: it tracks a running average, not per-entry history, so
  there is nothing else to rewrite. Preconditions mirror deciding: locks the same closure, re-runs
  the replay as a revalidation precondition (stale-flip + throw on mismatch, same as
  `DecideRecalculationApproval`), and additionally requires
  `RecalculationImpactVersion::hasUnexpiredFullApproval()` — ADR 0040's
  `ALL_REQUIRED_APPROVALS_PRESENT` and `ALL_REQUIRED_APPROVALS_UNEXPIRED`, using each approver's
  *most recent* decision so a renewal counts. One immutable `RecalculationExecution` row per
  request (DB-unique) is the Inventory-owned slice of the eventual
  `APPEND_ONLY_LINKED_CORRECTION_PACKAGE` — a retry resolves to the existing row rather than
  posting twice. SPECIFIC receipts execute too, trivially: a serial's layer group is scoped so
  tightly that nothing could have consumed a unit before it existed, so a new serial's
  `valuation_adjustment` is provably always exactly its own value (see
  `RequestStockRecalculationTest::test_specific_items_new_serial_computes_its_own_value_with_no_repricing_of_other_stock`)
  — a plain layer insert, no pool, no second entry. Scoped to what this codebase can execute
  correctly today: AVCO or SPECIFIC receipts only, and only when no negative-stock shortage exists
  for the item/location/batch.

**Not yet built**: authority-only approval rerouting (a changed approver's authority, not the
economics, requiring reroute rather than a fresh impact version — distinct from the same-actor
renewal above); FIFO execution (unlike SPECIFIC, a FIFO layer group can hold many layers, and a
backdated receipt's earlier `received_at` can shift which existing layer a later issue would have
drawn from in oldest-first order — fixing that needs correcting the immutable
`stock_layer_consumptions` ledger itself, real downstream re-linking, not just a converged average)
and issue/transfer execution; and the full multi-owner correction package — Sales/Job
Execution/Accounting routing, cross-module reconciliation and deferred obligations all need owning
modules that don't exist yet, so every case this codebase can execute today is "remaining stock,"
the one slice ADR 0040 lets Inventory own outright. No HTTP/API
layer exists for any of this, matching the negative-stock workflow, which doesn't have one either.

## Replay integrity and regeneration review

Reversal consumption rows carry `purpose = REVERSAL`: replay removes the exact original layer,
not whatever FIFO would allocate that day. Arrival comes from the layer's source `StockMove`;
`received_at` remains its FIFO age. Ties use movement id, then layer age/id for allocation.
The allocator must cover the entire historical draw; partial allocation cannot silently pass.

Before replay, every layer must reconcile `qty_received - SUM(qty_consumed) = qty_remaining`.
Older stock with missing ledger entries, including historical reversals, is refused until its
history is repaired through a separately controlled process; no guessed backfill is performed.
An open shortage or a shortage created/settled in the replay window is refused, including a
shortage created before the proposed receipt. Older settlement deductions without consumption
records also fail reconciliation. QA-controlled receipt histories are refused: today's QA
release totals cannot establish availability on a historical date. These are explicit unsupported
cases, not previews reporting invented quantity or value.

Initial calculation, decision revalidation and manual resumption share one impact builder.
It locks the physical location then the AVCO pool (when present), matching posting lock order.
Revalidation compares all stored economic figures, current risk and an immutable-history/period
fingerprint, rather than net adjustment alone. Closing a period or changing stock history can
therefore require fresh review even when the net adjustment is unchanged. Legacy versions lack
a fingerprint and require regeneration on their next decision. Stored versions remain immutable.
The database also serialises approval inserts and refuses stale-version or requester approvals;
the consumption ledger is immutable even through raw SQL.

The provisional regeneration interval is **three post-approval material invalidations**.
Pre-approval replacements count zero. The count is derived from stale versions with at least one
`APPROVED` decision, so manual review never resets it. At three, automatic replacement stops with
`PRIORITY_REVIEW`; after manual resumption, the next three require `HIGHER_SEVERITY`, as do further
cap hits. A paused request has no current executable impact; `RegenerationLimit::pending()`
exposes its stale version, cumulative count and escalation for future API/UI consumers.

`ResumeRecalculationRegeneration` requires an independent actor with
`inventory.recalculation.review-regeneration`, mandatory notes and, for repeated cap hits,
`inventory.recalculation.approve-high-risk`. It appends an immutable manual coordination record
and a freshly computed, unapproved impact atomically. Identical retries return the same
replacement. This is not approval, execution or a reset of the historical invalidation count.

Additive schema (annotated pseudo-DDL):

```sql
stock_layer_consumptions: purpose varchar NOT NULL DEFAULT 'ALLOCATION'
  CHECK purpose IN ('ALLOCATION', 'REVERSAL'); -- exact-layer reversal versus FIFO draw
recalculation_impact_versions: dependency_fingerprint varchar(64) NULL;
  -- NULL only for older versions; frozen with the other impact facts
recalculation_regeneration_reviews (
  id bigint PK, company_id bigint NOT NULL,
  impact_version_id bigint NOT NULL, reviewed_by bigint NOT NULL,
  invalidation_count integer NOT NULL, escalation varchar NOT NULL,
  notes text NOT NULL, reviewed_at timestamptz NOT NULL,
  created_at timestamp, updated_at timestamp,
  UNIQUE (company_id, id), UNIQUE (company_id, impact_version_id)
  -- composite tenant FKs to impact versions/users; all rows immutable
);
```

Rejected shortcuts: treating FIFO age as arrival time; guessing missing depletion records;
using today's QA releases for past allocation; treating equal net money as identical impact;
and resetting the invalidation count after manual review. Each loses facts needed for safe replay
or permits repeated approval churn without escalation.

## Live backdating

A receipt entered with an earlier date than existing issues means those issues may have been
costed from incomplete chronology. Live operation defaults to blocking a conflicting valuation
backdate while retaining separate truthful physical/source, ERP-entry, valuation-effective and
future accounting dates. A company may enable authorised forward recalculation, but entry never
triggers ordinary automatic replay: it creates an impact-previewed request requiring authorised
review. Independent approval is the default; an elevated audited policy may allow explicitly
identified self-review for a small company.

Approved replay is deterministic and transactional, preserves original movements/valuation
entries and appends linked adjustments. Immediately before execution it revalidates current
policy and authority, affected history, valuation state, period state, shortage dependencies and
hard integrity rules; stale approval cannot execute. Closed financial history is never rewritten
and migration authority remains isolated. Conflict/replay scope is method-specific: affected
configured pools and real cross-pool dependencies for AVCO, the affected layer/downstream
movement graph for FIFO, the exact serial chain for SPECIFIC and the compatible
settlement/adjustment chain for negative shortages. Unrelated pools are excluded. Preview may
overestimate conservatively; actual adjustments are limited to changed economic results, replay
runs to deterministic convergence and execution revalidates the dependency scope.

## Review, staleness and risk routing

ADR 0040 separates review delay, changed facts and ageing authority. Pending review has a
bounded company-configurable SLA (72 elapsed hours by default); breach marks `OVERDUE` and
escalates while preserving requester/approver separation, but does not invalidate the request.
Staleness is state-based. Each request owns immutable append-only impact versions, approval
targets one exact version, and execution revalidation automatically appends a fresh version
requiring fresh approval when material dependencies or economics change. Normal rolling-floor
clock progression alone is not stale. Approved execution authority has a hard bounded
company-configurable TTL (24 elapsed hours by default); expiry prevents execution. Trusted
server time and persisted deadlines govern, and later policy changes do not rewrite them.

Materiality is exact and semantic: changed executable economics/dependencies create an automatic
replacement impact version, while authority-only changes reroute/invalidate approval against the
same version. Every version is independently `NORMAL_RISK` or `HIGH_RISK`. Closed-period
effects, qualifying finalised downstream effects, controlled ordinary-tier self-review and later
defined non-disableable governance conditions are hard high-risk triggers requiring a second
independent approver. Optional bounded company thresholds use base/functional currency and may
strengthen, never disable, hard routing.

Final revalidation/execution serialises overlapping recalculations and locks the complete Q14.2
economic closure: affected configured AVCO pools plus cross-pool dependencies; FIFO layers,
allocations and downstream graph; the exact SPECIFIC serial/unit chain; and affected shortage
settlement/repair chains. Locking is never narrower than valuation state that can change the
deterministic result. Stale versions cannot execute. The bounded regeneration counter counts
only material invalidations after at least one approval, never pre-review recomputation; manual
resumption does not reset it and a repeated cap hit escalates more severely.

## The correction package

The correction workflow closes with an append-only linked correction package based on the exact
approved impact version. It preserves original and counterfactual results, exact approved delta,
historical dates and actual applicable FX bases; current-rate retranslation is not the default
and genuine FX effects remain separate. Deterministic replay allocates economic lines among
remaining Inventory value, consumed job costs, Sales/COGS and other economic owners without
changing physical quantity or mutating any original/finalised record. Accounting represents
those lines in the GL rather than adding another economic allocation; Tax, integrations and
ancillary obligations reconcile separately on their own bases.

Implemented synchronous participants declared inside one internal transaction execute
atomically; any failure rolls back all and cannot become a pending obligation after partial
internal commit. Unimplemented, intentionally asynchronous, Tax-deferred and external owners
remain explicit, monitored obligations delivered where applicable through a durable idempotent
outbox/correction event. The earliest permitted open period is the default posting point; no
valid point means pending/blocked, never automatic reopening. Full reconciliation requires
economic allocation to equal the approved delta, GL to reconcile to that allocation, ancillary
obligations to reconcile on their own bases and explicit rounding to leave no unexplained
residual.

The final operational details also require that Q13 immediate review records `JUSTIFIED` or
`FLAGGED`; the latter enters accountability/correction without automatically reversing stock,
and review remains separate from shortage settlement. Independent Q13 review is the default; an
explicitly recorded self-review is available only through a separately enabled, elevated and
audited small-company policy and does not itself invoke Q14. Separately, ordinary-tier
self-review of an actual Q14 impact is a mandatory high-risk trigger requiring its second
independent approval. Consequential company settings require elevated permission, mandatory
reason, actor, old/new values and timestamp without rewriting snapshotted deadlines/context.
Correction packages have unique stable identity and idempotent execution, so retries resolve
existing results instead of duplicating value. They expose cumulative
period/account/financial-line/prior-package and currency context for Accounting-owned
materiality. An unlinked manual journal cannot resolve a Q14 package, though legitimate manual
journals outside that workflow remain available.

## Backdating horizon

Backdating horizon is a separate eligibility dimension. The company defaults to current +
previous open period, or may select period-lock-only or one fixed/rolling posting floor. Rolling
floors use trusted server time in the company accounting timezone and snapshot request-time
context; normal clock progression does not stale an eligible request, but policy/boundary
changes can. The strictest cutover, period, valuation-policy and horizon boundary wins; no
per-request extension exists. Outside-horizon events retain physical truth, use the earliest
permitted valuation-effective point and send economic differences to the correction workflow
without falsifying physical dates.

## Related

- [stock.md](stock.md) — the core movement, layer and pool model
- [stock-movement-replay.md](stock-movement-replay.md) — connected issue/transfer preview
- [negative-stock.md](negative-stock.md) — shortage settlement chains replay must respect
- [inventory-cutover.md](inventory-cutover.md) — migration is not live posting
- [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md) — the
  consolidated decision
