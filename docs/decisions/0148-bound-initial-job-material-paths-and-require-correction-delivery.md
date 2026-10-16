[← Decision index](README.md)

# 0148 — Bound initial Job material paths and require correction delivery

- **Status:** accepted
- **Date:** 2026-10-16
- **Affects:** Phase 5 roadmap, Inventory/Job boundary; resolves Q53

## Context

The roadmap originally placed container tracking, package-aware previews and dependent variance
bands ahead of material design, while container delivery remained deferred. Grouped package v1
supports narrower fixed-content operations. Owner approved a bounded sequencing amendment on
2026-10-16, without activating any Job material integration or weakening Inventory invariants.

## Decision

Permit initial planning and eventual separately authorised implementation for company-owned,
fixed-content QUANTITY_ONLY and PACKAGE_BALANCE consumption using compatible AVCO/FIFO operations
and authoritative grouped-package issue evidence where applicable. Preserve container identity,
exact remnants, variable contents, serial/SPECIFIC and unsupported paths as future gates. Retain
their preview/variance obligations. No downgrade, scalar bypass or unsupported physical claim.

Separate demand, custody and consumption. Support truthful direct warehouse use without a mandatory
site/transfer, and supported site/vehicle custody followed by consumption. Transfers are not Job
expenses or parallel balances. Unused stock may transfer back; consumed returns require a separate
condition, quantity and valuation-correction contract. No generic manufacturing Work Order.

Initial availability is NOT RESERVED and posting revalidates Inventory. Explicit Inventory-owned
reservations remain separate managed research. Preserve Q24 ownership/exceptions and Q23 variable
content; refuse affected unsupported operations, never model third-party stock as zero-cost company stock.

Actual material cost comes from Inventory posting evidence. Deliver ADR 0040 Job correction
integration before activating EVERY supported consumed path. Corrections retain source lineage,
economic ownership, original valuation and idempotency without duplicate issues/cost or quantity
changes for valuation-only adjustments. Unsupported required effects fail closed under accepted
coordination rules; refusing replay is not a replacement for the activation gate.

Separate Job demand, stock posting, warehouse scope and sensitive cost rights. Compatible transactions,
locks, current authorisation and exact retries are required. Apply 0145 holds/termination/closeout;
reconcile custody, remaining demand, supported reservations and applicable corrections before closeout.

## Consequences

- **Positive:** a practical ordinary-stock first path without pretending advanced capability exists.
- **Negative:** some customer workflows remain unavailable until their genuine adapters are delivered.
- **Requires:** bounded path verification, owning adapters and mandatory 0040 integration before enablement.
  This decision changes sequencing only; accepted Inventory ADRs remain in force. No code authority.

## Alternatives considered

**Global container prerequisite** — delays supported simple paths unnecessarily.
**Global container waiver/scalar fallback** — loses physical evidence and advanced obligations.
**Treat transfers/PO values as actual cost** — confuses custody/commitments with consumption.
**Enable issues and add correction support later** — violates mandatory multi-owner correction delivery.

## Research basis

Reuse [M1–M6](../phases/phase-5-material-flow-research.md) and
[refined supported matrix](../phases/phase-5-coverage-material-resolution.md), accessed 2026-10-16.
ERPNext Stock Entry/reservation/customer-provided-item documentation distinguishes movement and
ownership; Dynamics consignment distinguishes custody from title. Their manufacturing/consignment
workflows are not copied into service Jobs. Existing Inventory code/contracts define the actual
supported subset; research does not prove a delivered Job adapter.

## Related

- [Material contract](../domain/job-material-attribution.md)
- [0040](0040-control-backdated-stock-recalculation-and-corrections.md)
- [0145](0145-separate-job-lifecycle-execution-and-operational-closeout.md)
- [Roadmap](../phases/README.md)
