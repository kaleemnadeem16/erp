[← Decisions](README.md)

# 0040 — Control backdated stock recalculation and corrections

- **Status:** accepted
- **Date:** 2026-09-02
- **Affects:** Phase 2 Inventory and Purchasing, downstream job/sales costs, Tax, integrations and
  Phase 6 Accounting

## Context

A live receipt entered after later costed movements can change the chronological AVCO, FIFO or
SPECIFIC result. Silently backdating it would falsify entry history; automatically rewriting later
valuation would bypass review; always refusing it would leave genuine late information
unrecognised. Recalculation may reach negative-stock repairs, closed accounting periods,
finalised jobs or sales records, historical FX bases and external consumers.

This policy governs live post-cutover operation. Controlled predecessor history remains separate
under [ADR 0036](0036-controlled-inventory-cutover.md). Negative-stock authority remains separate
under [ADR 0037](0037-controlled-negative-stock-overrides.md), and configured AVCO pool identity
under [ADR 0039](0039-configurable-avco-valuation-pools.md).

## Decision

### Live policy and truthful dates

Live operation defaults to `BLOCK_CONFLICTING_VALUATION_BACKDATE`. A company may explicitly enable
`AUTHORISED_FORWARD_RECALCULATION`; ordinary automatic replay is never supported. A conflict
exists when the proposed valuation-effective receipt precedes a later costed movement whose
economic result can change.

Physical/source date, ERP-entry timestamp, requested and permitted valuation-effective dates and
accounting posting date remain separate and truthful. A conflicting receipt creates an
impact-previewed request; only an approved, current impact version may execute. Original movements
and valuation entries are never mutated.

### Replay dependency scope and final locking

Replay follows the actual method-specific economic dependency closure:

- AVCO: affected configured `COMPANY_ITEM`, `WAREHOUSE_ITEM` or `COMPANY_ITEM_BATCH` pools plus
  actual cross-pool dependencies. `WAREHOUSE_ITEM_BATCH` remains deferred.
- FIFO: affected layers, allocations and downstream transfer/consumption dependencies.
- SPECIFIC: the exact serial/unit dependency chain.
- Negative shortage/repair: the affected compatible settlement and repair dependency chain.

Unrelated state is excluded. A preview may conservatively overestimate scope, but execution adjusts
only changed economic results and runs to deterministic method-specific convergence.

Final revalidation and execution serialise overlapping recalculations and acquire locks in a
deterministic order: AVCO locks affected configured pools plus cross-pool dependency closure; FIFO
locks affected layers, allocations and downstream dependency graph; SPECIFIC locks the exact
serial/unit chain; shortage/repair locks the affected settlement/repair chain.

Final locking covers the same economic closure as replay and is never narrower than valuation
state capable of changing the deterministic result. After locking, execution revalidates the
version, dependencies, economics, risk, authority, periods, policy, shortages and hard integrity.

For the accepted provisional cross-pool AVCO edge case, an exact physical source location can be
positive while its wider `WAREHOUSE_ITEM` pool is non-positive because another location in that
pool is negative. This is valid physical provenance, not a negative-source transfer. Carry the
source pool's last defensible provisional AVCO into the destination, mark both valuation effects
provisional and link them to the same recovery chain. Source recovery appends paired propagated
adjustments while preserving company-value neutrality. `NEGATIVE_PHYSICAL_SOURCE_TRANSFER`
remains blocked.

### Backdating horizon

Each company selects one horizon mode:

- `CURRENT_AND_PREVIOUS_PERIOD` — default.
- `PERIOD_LOCK_ONLY`.
- `POSTING_FLOOR` with exactly one `FIXED_DATE` or `ROLLING_FORMULA` subtype.

A rolling floor uses trusted server time in the company accounting timezone, never browser,
device, session or user-editable work time. Request creation snapshots the mode/formula, trusted
timestamp, timezone and calculated floor. Normal clock progression alone does not stale a request
that was eligible when created; actual formula, floor, mode, period or other boundary changes may.

The strictest cutover, accounting-period, valuation-policy and horizon boundary wins. Per-request
extensions are forbidden. Outside-horizon events retain their physical/entry truth, use the
earliest permitted valuation-effective point and route the economic difference through the linked
correction workflow.

### Request review, timing and separation

One recalculation request represents one continuing business case and owns immutable append-only
impact versions. Pending review has a company-configurable SLA within finite platform bounds,
defaulting to 72 elapsed hours. Breach marks the request `OVERDUE`, notifies/escalates it and leaves
it reviewable after current-state revalidation; there is no mandatory pending-request hard expiry.

Requester/approver separation is required throughout assignment and escalation. Escalation cannot
route approval authority back to the requester. Any small-company exception is separately enabled,
elevated, visible and audited.

### Staleness, impact versions and authority changes

`OVERDUE`, `STALE` and `APPROVAL_EXPIRED` are distinct conditions and none changes stock by itself.
Staleness is state-based, not time-based.

Approval targets one exact immutable impact version, never the parent request generally. A
material change preserves the old version, marks it stale/non-executable, automatically appends a
replacement under the same request, recalculates risk and requires fresh approval. Stale versions
never execute.

Materiality is exact and semantic. A fresh version is required when executable economics or
dependencies change, including affected movements, pools, layers/allocations, serial chains,
shortage repair, downstream membership, stored transaction/base-currency results, adjustment
allocation, period/correction routing or applicable valuation/hard-integrity treatment. Unrelated
and presentation-only changes, or recomputation producing the identical executable dependency set
and stored result, do not invalidate approval.

Policy changes affecting executable economics/dependencies may stale the impact. Authority-only
changes—an approver losing permission/becoming ineligible or economic-neutral approval routing—use
`AUTHORITY_ONLY_CHANGE: REROUTE_OR_INVALIDATE_APPROVAL_WITHOUT_NEW_IMPACT_VERSION`.

Normal `ROLLING_FORMULA` clock progression remains non-stale by itself under the request-time
horizon snapshot.

### Regeneration cap

Automatic replacement generation is bounded using
`REGENERATION_COUNTER_BASIS: POST_APPROVAL_MATERIAL_INVALIDATIONS`.

The counter increments only when a version received at least one approval and later became
materially stale. Pre-approval recomputation counts zero. At the first cap hit,
`FIRST_CAP_HIT: PAUSE_AUTOMATIC_REGENERATION + PRIORITY_MANUAL_REVIEW`. This neither cancels the
parent request, executes a stale version, bypasses approval nor fabricates a correction. After
manual coordination, execution still requires a fresh current impact version and all required
approvals.

Manual intervention does not reset the accumulated counter:
`MANUAL_RESUMPTION_RESETS_COUNTER: NO`. If the same parent reaches the cap again,
`SECOND_CAP_HIT: HIGHER_SEVERITY_ESCALATION`; it does not restart the same priority-review cycle.
Stale versions remain non-executable throughout. The finite cap value is a provisional product
parameter, not an accounting invariant.

### Risk classification and approval authority

Every impact version is independently `NORMAL_RISK` or `HIGH_RISK`; replacement versions may move
up or down. `HIGH_RISK` requires a second approver who holds dedicated higher-risk authority and is
distinct from both requester and first approver. No eligible independent approver means execution
blocks.

Mandatory `HIGH_RISK` triggers while their underlying condition exists are:

- a closed-period financial consequence;
- a qualifying finalised downstream consequence, where the owning module's shared
  correction/reversal test says ordinary change is no longer possible;
- use of an allowed self-review exception at the Q14 ordinary approval tier; and
- any later non-disableable correction/governance trigger established by this policy family.

Companies may add bounded base/functional-currency or other routing thresholds, lower thresholds
or add stricter triggers. They cannot disable a hard trigger.

Q13 `IMMEDIATE_WITH_REVIEW` is a separate post-event workflow for an already-posted negative-stock
override. It defaults to independent review; self-review is allowed only through a separately
enabled, elevated and audited small-company policy and must retain explicit self-reviewed status,
actor, reason, `JUSTIFIED`/`FLAGGED` outcome and policy basis. It is never presented as independent
review and does not automatically invoke Q14. Only ordinary-tier self-review of an actual Q14
impact is the mandatory high-risk trigger above. The workflows connect only when the negative-stock
event actually participates in a Q14 dependency closure and that impact independently meets Q14
conditions.

### Approved execution lifetime

Approved execution authority has a hard company-configurable TTL within finite platform bounds,
defaulting to 24 elapsed hours. The expected flow is approve, lock, revalidate and execute. Expired
authority becomes `APPROVAL_EXPIRED`, cannot execute and requires fresh review/approval. Unlimited
authority and per-request extensions are unsupported.

`APPROVAL_TTL_TARGET: EACH_REQUIRED_APPROVAL`. Each required approval receives its own persisted
expiry timestamp when granted. Execution requires `ALL_REQUIRED_APPROVALS_PRESENT`,
`ALL_REQUIRED_APPROVALS_UNEXPIRED` and
`ALL_REQUIRED_APPROVALS_TARGET_CURRENT_EXACT_IMPACT_VERSION`. A later second-tier approval does not
refresh or revive an expired first-tier approval; every expired required tier must be approved
again before execution. Exact-version matching and execution-time revalidation still apply.

Trusted server time governs all deadlines. The request persists its review due timestamp and an
approval persists its execution expiry. Later settings changes affect future transitions only and
never silently rewrite existing deadlines or request-time horizon snapshots.

### Consequential company-policy governance

Changes to negative-stock override eligibility/mode, authorised-forward-recalculation enablement,
horizon mode/parameters, review SLA, approval TTL and comparable live valuation or approval policy
require elevated permission, mandatory reason, actor, old value, new value and timestamp.

Their effect on current requests follows the economic-policy-versus-authority-only distinction
above.

### Append-only linked correction package

Closed operational history, original movements, original valuation entries and finalised
downstream records are never mutated. Accounting periods are never reopened automatically. The
approved method is `APPEND_ONLY_LINKED_CORRECTION_PACKAGE`, based on the exact approved impact
version.

`CORRECTION_PACKAGE_IDENTITY: IMMUTABLE` and
`CORRECTION_PACKAGE_ECONOMIC_PAYLOAD: IMMUTABLE / APPEND_ONLY`. The economic payload retains at
least the parent request, exact approved impact version, original recorded result, counterfactual
chronological result, approved correction delta, affected economic dependencies, truthful
physical/source and ERP-entry dates, relevant historical periods, historical FX bases, approved
allocation basis and immutable correction references.

`CORRECTION_PACKAGE_LIFECYCLE_STATE: MAY_PROGRESS_WITH_FULL_AUDIT_HISTORY`. Workflow may progress
through states such as `PENDING` → `EXECUTING` → `POSTED` → `PENDING_DOWNSTREAM` →
`FULLY_RECONCILED`; delivery, obligation, retry and reconciliation statuses may likewise progress.
Every transition retains actor, timestamp, prior/new state and relevant reason or outcome. Approval
facts are immutable records that may be appended as required approvals are granted or refreshed.

If the approved economic result later needs correction, never mutate the existing package. Append
another explicitly linked correction package—for example, `CP-100: +12,000`, followed later by
`CP-101: -2,000 linked to CP-100`; do not rewrite CP-100 to +10,000.

Default operational posting is the earliest permitted open accounting point. If none exists, the
obligation remains explicitly `PENDING` or `BLOCKED`; Inventory never invents a posting date or
reopens a period. Accounting—not Inventory—classifies prior-period error, estimate change,
ordinary late information or other treatment and owns materiality/restatement/presentation under
the applicable accounting framework.

### Deterministic allocation, FX and module ownership

One package may contain multiple owners. Deterministic method-specific replay—not an assumed
proportional split—allocates the economic delta: remaining stock may require an Inventory
valuation adjustment, sold stock a Sales/COGS correction and job-consumed stock a Job Execution
correction. Valuation correction never changes physical quantity.

The package preserves original and counterfactual transaction-currency amounts, their actual
applicable historical FX bases and functional/base values, plus transaction- and base-currency
correction deltas. The historical basis may be applicable spot, permitted average or another
approved company/accounting basis. The valuation correction's base amount is the exact approved
counterfactual delta; universal current-rate retranslation is unsupported. A genuine separate FX
effect is identified, classified and recognised separately.

Inventory calculates and coordinates, while each module corrects its own finalised economic
record: Inventory owns valuation adjustments; Job Execution owns job-cost corrections; Sales owns
COGS/margin corrections; Accounting owns balanced journals and reporting; Tax owns explicit
tax/VAT/duty obligations; integrations own correction delivery.

### Atomicity and deferred obligations

Implemented synchronous participants declared inside one internal database transaction use
`INTERNAL_ATOMIC_SCOPE_FAILURE: ROLLBACK_ALL` and `PARTIAL_INTERNAL_DB_COMMIT: NO`.

A failed participant inside that boundary cannot become `PENDING_DOWNSTREAM` after the others
commit.

Explicit pending obligations are supported only for later-phase/unimplemented modules,
intentionally asynchronous owners, external integrations and deferred Tax or other owners outside
the current atomic boundary. Each retains owner, type/basis, amount/dependency, status, reason,
timestamps, active monitoring/escalation and eventual resolution reference. External delivery uses
a durable idempotent outbox/correction event. Overdue status never invents a correction or bypasses
the owner, and unresolved required obligations prevent false full reconciliation.

### Idempotency and reconciliation

Every package has stable unique identity: `CORRECTION_PACKAGE_IDENTITY: UNIQUE`,
`CORRECTION_PACKAGE_EXECUTION: IDEMPOTENT` and `DUPLICATE_EXECUTION: PREVENTED`.

A retry after an ambiguous timeout resolves to the existing package/result and never posts the
approved delta again. External outbox/event idempotency remains separately required.

Reconciliation distinguishes economic allocation from its representations and ancillary bases:
the sum of economic correction allocation lines equals the exact approved correction delta; GL
postings reconcile to those allocation lines; Tax, integration and ancillary obligations reconcile
separately on their own bases; and `UNEXPLAINED_RESIDUAL = ZERO`.

Accounting representation is not an additional economic allocation and integrations communicate
without adding value. Legitimate rounding is explicitly identified and posted through the defined
rounding treatment. `FULLY_RECONCILED` requires full economic allocation, GL reconciliation,
resolution/reconciliation of required ancillary and downstream obligations on their own bases and
no unexplained residual.

The package exposes cumulative affected periods, accounts, known financial-statement lines,
related correction packages and transaction/base-currency amounts.
`ACCOUNTING_MATERIALITY_ASSESSMENT: ACCOUNTING_OWNED`; Inventory supplies evidence/context but does
not decide materiality or restatement.

`MANUAL_UNLINKED_JOURNAL_AS_Q14_CORRECTION_WORKFLOW: NOT_SUPPORTED`. Legitimate manual journals
remain possible elsewhere, but an unrelated journal cannot resolve a Q14 obligation because the GL
representation must retain correction-package linkage for traceability, idempotency and
reconciliation.

## Consequences

- **Positive:** Late information is recognised without falsifying dates or rewriting history.
- **Positive:** Exact-version approval, method-specific locking and package idempotency keep the
  executed economic result deterministic and auditable.
- **Positive:** Q13 operational review remains separate from Q14 retrospective valuation approval.
- **Positive:** Economic allocation, GL representation, Tax and integration obligations reconcile
  without double counting while retaining historical FX context.
- **Negative:** Dependency replay, multi-tier approval, deterministic locking, cross-module
  correction packages and cumulative reconciliation add substantial complexity.
- **Negative:** Execution can remain blocked without an open accounting point, eligible approver,
  implemented owner or external recipient.
- **Requires:** Real PostgreSQL concurrency, stale-version, regeneration-cap, timeout retry,
  atomic rollback, FX and zero-residual reconciliation tests before the valuation engine ships.
- **Requires:** Each module declares its finalised-state correction/reversal boundary and whether
  its correction is synchronous/atomic or explicitly deferred/asynchronous.

## Alternatives considered

- **Always block conflicting backdates** — genuine late information would remain unrecognised.
- **Automatic replay or parent-request approval** — retrospective change requires preview and
  authority over one exact current result.
- **Monetary tolerance as semantic materiality** — changed layers, serials, periods or dependencies
  can matter when net value is small or unchanged.
- **Rewrite final history or reopen automatically** — breaks immutability and prior reporting.
- **Lock only the receipt/first valuation row** — the result depends on the full economic closure.
- **Count every regenerated preview** — pre-review recomputation is not invalidated human review.
- **Treat Q13 self-review as automatic Q14 high risk** — Q13 can complete without Q14; Q14
  classifies its own exact impact.
- **Current-rate retranslation** — contaminates historical comparison; real FX effects are separate.
- **Convert internal failure to pending work** — permits partial commit inside an atomic boundary.
- **Sum GL, Tax and integrations into economic allocation** — double-counts/mixes bases.
- **Accept an unlinked manual journal** — prevents proof of allocation linkage and duplicate control.

## Related

- [ADR 0036 — Controlled inventory cutover](0036-controlled-inventory-cutover.md)
- [ADR 0037 — Controlled negative-stock overrides](0037-controlled-negative-stock-overrides.md)
- [ADR 0039 — Configurable AVCO valuation pools](0039-configurable-avco-valuation-pools.md)
- [Stock](../domain/stock.md)
- [General ledger](../domain/general-ledger.md)
- [Phase 2 — Purchasing & stock](../phases/phase-2-purchasing-stock.md)
