[← Decisions](README.md)

# 0037 — Control negative stock through explicit overrides and settlement

- **Status:** accepted
- **Date:** 2026-09-01
- **Affects:** Phase 2 Inventory and Purchasing, Phase 5 Job Execution, Phase 6 Finance,
  inventory cutover, stock valuation and audit

## Context

Live outbound movements can be recorded after the physical event because receipt, transfer or
consumption paperwork is late. Always blocking negative stock preserves clean valuation but may
stop genuine despatch or production. Unrestricted negative stock is worse: it fabricates source
provenance, leaves AVCO/FIFO without real supporting inventory and can conceal discrepancies.

The ERP therefore needs one policy covering where availability is measured, which movements may
be overridden, who authorises them, how provisional cost is assigned and repaired, and whether a
predecessor system's genuine negative closing balance may become opening state. Historical source
facts remain separately governed by [ADR 0036](0036-controlled-inventory-cutover.md).

## Decision

Live negative stock is `BLOCK` by default. A company may explicitly enable
`AUTHORISED_OVERRIDE`, but only through the constrained controls below. An override records a
genuine outbound or consumption event while paperwork is behind; it cannot fabricate provenance,
silently repair balances, invent costs or bypass hard inventory constraints.

### Availability and hard boundaries

Availability is checked at the exact source location and tracking identity: item + location for
untracked stock, item + location + batch for batch tracking, and the exact serial at that location
for serial tracking. Stock elsewhere must be issued where held or moved by an explicit supported
transfer. Companies may use one default location per warehouse or granular bins. This physical
scope does not determine AVCO or other valuation scope.

Missing serial/SPECIFIC identity, a physical-count result below zero, tenant violations, invalid
tracking/UoM identity, closed-period restrictions and other hard integrity rules are never
overrideable.

### Eligible movement classes

Companies using `AUTHORISED_OVERRIDE` configure eligibility only across sales/despatch issues,
job/work-order material issues, supplier returns, scrap/write-off and other explicitly classified
consumption. The conservative initial matrix enables sales/despatch and material consumption as
candidates; the other classes start disabled.

Internal transfers are permanently excluded because a transfer asserts supported provenance from
one location to another. Corrections and reversals use a separate dependency-aware workflow that
creates explicit correcting transactions after checking downstream inventory, QA, movement,
valuation, matching and period dependencies. Posted history is never silently edited.

Eligibility changes require elevated inventory-policy permission and an audit record containing
the actor, reason, old/new values and timestamp.

### Transaction authorisation and review

Each eligible class uses company-configured `PRIOR_APPROVAL` (the default) or
`IMMEDIATE_WITH_REVIEW`:

- Prior approval requires a different user with dedicated approval permission. It never reserves
  stock; availability and hard rules are rechecked transactionally. If stock is then sufficient,
  the movement posts normally. Approval covers only the reviewed shortage and cannot authorise a
  materially worse one.
- Immediate posting requires dedicated override permission, then mandatory review within 24
  elapsed hours by default. The company may configure the SLA within a system-defined maximum.
  Missed deadlines become overdue, escalate and remain reportable without automatically reversing
  the movement. Review records `JUSTIFIED` or `FLAGGED`. Independent review is the default; an
  audited policy may permit explicitly reported self-review for a small company. Prior-approval
  self-approval is never allowed.

Both modes require an explicit shortage warning, controlled reason code, written explanation and
source reference where applicable. API and UI never apply an override silently. The immutable
record retains the company, item, exact location/tracking identity, movement/source, quantities
before and after, actor, reason, timestamps, approval/review facts, provisional valuation and
later settlement references.

Review and physical shortage are separate states. Shortage state supports at least `OPEN`,
`PARTIALLY_SETTLED` and `SETTLED`; a justified override may remain an open shortage.

### Provisional valuation and settlement

Unsupported AVCO/FIFO quantity uses the best defensible system-derived cost and is explicitly
provisional:

- AVCO uses the last valid official average at its applicable valuation scope. Normal weighted
  averaging is suspended while that valuation pool is zero or negative. When genuine inbound
  quantity makes an exhausted pool positive, shortages and adjustments are applied first and the
  new average uses only the positive remainder; exhausted pre-negative inventory is not blended
  back in.
- FIFO uses the last defensible actual acquisition cost and an explicit shortage record, never a
  fake receipt layer.
- If method history is unavailable, cost may come from the expected inbound source linked to the
  physical goods, or exactly one unambiguous eligible expected inbound. The system never guesses
  between plausible sources or accepts a free-form provisional cost. Ambiguity or no defensible
  cost blocks the override.

Compatible real inbound quantity, including a valid costed transfer into the location, settles
the oldest compatible shortage first by company, item, exact location and batch. Partial
settlement is allowed; only excess inbound quantity becomes ordinary positive stock/layers.
Settlement is transactional and appends a linked valuation adjustment containing provisional and
actual costs, settled quantity, difference and settling reference. Original movements and
valuation entries are never mutated.

### Negative opening at cutover

The dedicated inventory-cutover workflow may preserve an evidenced AVCO/FIFO negative opening
when forcing it to zero would falsify the predecessor system's approved closing position.
SPECIFIC/serial negatives and invented location or batch provenance are blocked.

Each exception requires company, item, exact location, compatible batch where applicable,
official source quantity/value and identity, defensible provisional cost, supporting evidence,
reason, responsible owner, resolution plan and target date. Dedicated cutover approval and full
quantity/value control-total reconciliation are mandatory. It creates an `OPEN` shortage settled
under the rules above.

The target defaults to 30 elapsed days after cutover; an alternative requires explicit approval
with its reason retained. Overdue openings escalate and remain reported but never generate an
automatic stock event. Cutover authority is unavailable to ordinary APIs, generic-import direct
writes and reusable-template bypasses; it neither grants live override permission nor permits the
shortage to deepen outside normal live policy. Finalised cutover remains frozen.

## Consequences

- **Positive:** Genuine urgent outbound events can be recorded without making negative stock an
  invisible or routine workflow.
- **Positive:** Exact-location provenance, batch/serial integrity and immutable valuation history
  remain auditable.
- **Positive:** AVCO/FIFO shortages receive defensible provisional values and explicit later
  repair instead of zero, guessed or silently rewritten costs.
- **Positive:** Genuine predecessor closing balances can be preserved without exposing migration
  authority to live users.
- **Negative:** Override requests, approvals, reviews, exception queues, shortage settlement and
  linked valuation adjustments add substantial workflow and schema complexity.
- **Negative:** Interim job, margin and financial feeds remain provisional until shortages settle.
- **Requires:** All quantity/valuation changes pass through one transactional Inventory Action
  with concurrency-safe availability, settlement and AVCO/FIFO locking.
- **Requires:** Phase 6 posts linked valuation repairs as explicit adjustments and never rewrites
  closed financial history.
- **Requires:** Operational reporting distinguishes review outcome, overdue review and shortage
  settlement rather than collapsing them into one status.

## Alternatives considered

**Always block live negative stock** — strongest valuation control, but rejected as the only mode
because real operations may precede paperwork and blocking can stop despatch or production.

**Warning or unrestricted negative stock** — rejected because it creates fictional inventory,
weakens provenance and requires uncontrolled cost repair.

**Warehouse- or company-wide availability** — rejected because stock elsewhere does not prove the
selected source location holds it and would undermine counts and tracking.

**Permit negative-source transfers** — rejected because it manufactures positive destination
stock and corrupts movement/layer lineage.

**Always require prior approval** — rejected as the sole workflow because urgent operations and
small companies need a bounded immediate path; prior approval remains the safe default.

**Zero or user-entered provisional cost** — rejected because zero distorts interim reporting and
free-form estimates are subjective and manipulable.

**Never permit negative opening state** — rejected as absolute policy because forcing a genuine
approved predecessor closing balance to zero would falsify cutover. The exception is isolated and
more tightly controlled than live posting.

## Related

- [ADR 0032 — Per-item valuation method](0032-per-item-valuation-method.md)
- [ADR 0036 — Controlled inventory cutover](0036-controlled-inventory-cutover.md)
- [Stock](../domain/stock.md)
- [Inventory cutover](../domain/inventory-cutover.md)
- [Phase 2 — Purchasing & stock](../phases/phase-2-purchasing-stock.md)
