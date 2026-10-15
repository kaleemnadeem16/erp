[← Decisions](README.md)

# 0140 — Publish Sales measures and dated forecasts with persistent trust

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Phase 4 R9 A8–A10; Sales, Documents/Company and owning source/approval/reporting adapters
- **Authority:** owner's A1–A10 approval with safeguards after local c25d5cb; architecture/documentation only, no application code/live permissions/grants authorised.

## Context

R1–R8 and Documents0137 have accepted business boundaries. The final R9 review reconciles duties,
scopes, limits and reporting without changing their historical accepted bodies. Actual delivery,
public enablement and real holder decisions remain distinct from architecture approval.

## Decision

Keep accepted commitments, eligible available offers, blocked scope, unoffered potential,
dispositions and fulfilment distinct. Reuse OPEN/WON/LOST/ABANDONED and derived partial-win indication.
No automatic whole-Opportunity WON after partial acceptance. A successful closed pursuit may be WON
with accepted part and recorded remaining disposition under0107; never pretend every offered quantity
was accepted. Quote revisions, Orders, Jobs and deliveries are not extra sales.

Reuse MAINTAINED and SELECTED_OFFERS under0101/0102. Forecast valuation is explicit-dated with
company-base bridge, exact-date default and optional governed bounded latest-prior fallback.
Missing/unsupported rates mean incomplete valuation, never zero or implicit substitution. Pin
reporting rate/date/source/route/policy/currency and financial evidence. Combined accepted+remaining
management view needs one disclosed comparable valuation date/currency/policy; original commercial
amounts remain immutable. No initial probability-weighting, GL/revenue or statutory effect.

Trust invalidation persists until explicit eligible review/publication version. Changed relied-on
rate value/date/source validity requires review regardless of materiality initially; ordinary later-
date movement does not invalidate dated historical evidence. Technical recovery cannot restore TRUSTED.
History separates known-as-of recording time from effective business time; supported restatements
are labelled and do not rewrite originals. Record/field scope applies before aggregate/export;
visible totals cannot disclose hidden competitors/contributions. Current trusted reports cannot
depend only on periodic polling where authoritative changes can invalidate their source basis.

## Consequences

- **Positive:** explainable duty/authority/evidence and reporting with no duplicate business commitments.
- **Negative:** unsupported adapters, missing required evidence and unsafe rollout mappings refuse enablement.
- **Requires:** [duty matrix](../domain/sales-duty-matrix.md), [authority contract](../domain/sales-authority.md), [reporting contract](../domain/sales-reporting-and-forecast.md), [rollout specification](../phases/phase-4-r9-permission-rollout.md) and actual integrated verification.
- **State:** ACCEPTED architecture / SPECIFIED contracts; no new R9 feature IMPLEMENTED or VERIFIED by this record.

## Alternatives considered

Sum related quotations/targets — double-counts alternatives, revisions and stages.
Subtract acceptance from maintained estimate — silently changes maintained assumptions.
Latest FX on read / auto-trust on technical recovery — rewrites history or bypasses review.
One historical date or unscoped totals — fabricates past knowledge or leaks hidden records.

## Research basis

Reuse [final R9 A1–A10 comparison and inspected contracts](../phases/phase-4-r9-authority-reporting-review.md),
primary ERPNext authorisation-rule and Dynamics forecast-permission evidence accessed2026-10-15,
plus accepted0101/0102/0106/0107/0114/0116/0070/0085/0087 and0137. Products separate conditional
authority and forecast access but differ on role/hierarchy rules; project capability/scope semantics
remain governing. Owner-approved refinements settle remaining choices, not vendor defaults.
Reused evidence answers these questions; no repetitive search or application test was needed to
record architecture approval. Exact repository vocabulary was inspected read-only before specification.

## Related

- [R9 slices](../phases/phase-4-r9-implementation-slices.md) · [Consolidated handoff](../phases/phase-4-implementation-handoff.md)
- [Decision index](README.md) · [Authorisation](../architecture/authorization.md)
