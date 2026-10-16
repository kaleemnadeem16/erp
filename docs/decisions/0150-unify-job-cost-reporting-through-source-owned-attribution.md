[← Decision index](README.md)

# 0150 — Unify Job cost reporting through source-owned attribution

- **Status:** accepted
- **Date:** 2026-10-16
- **Affects:** Phase5 Q55/U1–U5; source attribution, reporting and later physical design
- **Authority:** owner business-architecture approval; no application or final physical-schema authority

## Context

Unlike operational sources must contribute once to a trustworthy Job view. Captured money, complete
valuation and complete expense coverage are different. Shared reporting must not compete with
owning sources or invent estimates, final profitability or historical reconstruction.

## Decision

Use one coherent source-owned economic attribution model, with linked recognition, allocations and
append-only corrections; shared logical attribution/correction foundation and rebuildable authorised
projections. Reuse verified conventions. No competing editable Job ledger, duplicate economic posting,
unnecessary event bus or fixed table design. Reports/rebuilds never independently repost a source.

Initial measures: original estimate/operational baseline and current authorised plan where available;
recognised known actual by source/category; linked corrections; separate outstanding commitments,
reported exposure and unpriced/uncertain recognised contributions; source valuation/category coverage/
overall completeness; explicitly compatible spending gaps. Provisional valuation must be distinct
from fully supported valuation and never imply financially final cost. Missing is not zero.
No reliable efficiency/final variance/profitability/revenue recognition from incomplete operational data.

Prioritise current-restated reporting, preserving immutable dated evidence where authoritative source
snapshots support it. Keep incurred-through, known/recorded-as-of, generations, selected baseline and
correction lineage distinct. No arbitrary historical reconstruction without verified coverage. Later
corrections update supported current views without overwriting original sources/reports or quantity
for valuation-only adjustments. Preserve monetary precision and supported FX; never mix currencies.

Defer ETC/EAC from first implementation scope. Preserve accountable future remaining-work/cost basis,
author/date/generation, commitment overlap, accepted-unpriced work, uncertain claims and completeness.
Spend/open POs/manual progress cannot establish final-cost predictions.

Reuse0145–0149/R7/R9 and independent completeness axes. Current access guards all projections,
snapshots/drill-down/exports and derived inference. Authoritative reports require verified source
adapters, compatible snapshots/generations and correction reconciliation.0040/0149 gates remain
mandatory. Missing required effects refuse safely; allocation conservation/idempotency are testable.
Physical storage, exact locking and projection implementation remain later design/verification.

## Consequences

- **Positive:** one auditable economic view without changing source authority or forcing optional capture.
- **Negative:** incomplete/withheld/incompatible data needs explanation instead of a misleading total.
- **Requires:** [bounded contract](../domain/job-unified-costing.md), verified adapters/consistent reads,
  current authorisation and correction/rebuild tests before reporting activation. No delivery implied.

## Alternatives considered

**Editable central Job ledger** — competes with source owners and loses correction lineage.
**Blind union/sum of all documents** — duplicates invoice/operational/stock representations.
**Automatic EAC or efficiency from spending/progress** — invents remaining work and comparability.
**Universal historical replay** — unsupported unless every source's as-of history is proven.

## Research basis

Reuse [Q55 primary comparison](../phases/phase-5-unified-costing-research.md) and
[refinement/infrastructure inspection](../phases/phase-5-unified-costing-resolution.md), accessed2026-10-16.
Dynamics immutable actual/correction conventions, ERPNext source roll-up and Odoo separate stage
measures support evidence-based aggregation, while their financial/time-entry assumptions differ.
Local inspection found reusable CRM receipts/correction conventions and Inventory-specific valuation,
not a generic delivered Job projection engine. Reuse suitable conventions, not those domain tables.
Owner selects spend/commitment/exposure first and current-restated history without advanced forecasts.

## Related

- [Unified costing](../domain/job-unified-costing.md) · [Phase5](../phases/phase-5-job-execution.md)
- [0147 coverage](0147-bound-job-cost-coverage-to-scope-evidence-and-source-version.md)
- [0149 external](0149-attribute-external-job-costs-through-owning-sources-and-reconciliation.md)
