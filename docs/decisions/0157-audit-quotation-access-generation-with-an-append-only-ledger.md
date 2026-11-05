[← Decisions](README.md)

# 0157 — Audit quotation access generation with an append-only ledger

- **Status:** accepted (owner approval); implementation and verification in progress
- **Date:** 2026-11-05
- **Affects:** QA5 quotation sharing, ownership/access consistency, PostgreSQL storage and issue #77

## Context

The QA1a database guard ties family access_generation to its latest ownership event. QA5 shares
need to advance access generation independently while preserving the ownership chain. The owner
approved the concrete [ledger proposal](../domain/quotation-access-generation-extension.md).

## Decision

Use one additive migration, `2026_11_10_020000_create_quotation_access_events_and_shares`, to add
an audited append-only access-event ledger for unit changes, grants and revocations and the typed
share evidence it references. The guard rule remains: access_generation equals the latest event.
Owner/unit/baseline/ownership-generation projections still equal their latest ownership event.

- Preserve existing owner/unit history exactly. Backfill access events from immutable source
  events with explicit origin and truthful recording time; do not fabricate historical events.
- Enforce strict same-company/family predecessor, typed-source and generation chains, with
  append-only UPDATE/DELETE and TRUNCATE guards on all new evidence.
- Populated rollback refuses. Empty rollback restores the previous guards. Existing migration
  files and existing tests remain unchanged for this extension; add new tests.
- Prove concurrent ownership change plus share, replayed share, revoke after expiry, wrong-company
  and OWN/MY_UNITS/ALL cases with non-disclosing refusals. Retain all existing security coverage.
- Apply only to private test databases during implementation. Never migrate the owner's `erp`
  database. Shipped duties and production activation remain gated; this creates no holder grants.

The separate bootstrap remains governed by 0155 and needs exact-SHA review and CI before merge.
The later sharing consumer may require a narrowly reviewed update to an old unsupported-scope
assertion; that separate request is pending and does not permit changing it in this ledger step.

## Consequences

- **Positive:** one audited access-generation chain accounts for ownership-unit and share changes;
  ownership evidence retains its original meaning and values.
- **Negative:** the new migration must backfill and reconcile two related chains under stable
  locks; raw SQL and concurrent transition proofs are essential.
- **Requires:** additive migration, composite keys, immutable evidence, strict tail/projection
  checks, populated refusal and all owner-specified tests before the extension is handed off.

## Alternatives considered

**Separate sharing counter** — rejected by the owner in favour of the audited common access ledger;
would require another expected-generation API/client contract.

**Skip the guard, leave the access counter unchanged or invent ownership events for shares** —
rejected: these erase the intended evidence or staleness guarantees.

## Research basis

Local consistency/evidence implementation of the existing [record-access design](../domain/quotation-record-access-design.md)
and [storage contract](../domain/quotation-record-access-storage.md), with the owner's explicit
ledger decision. No new ERP sharing business semantics or external comparison is introduced.

## Related

- [Access-generation contract](../domain/quotation-access-generation-extension.md)
- [Current access checkpoints](../domain/quotation-access-qa2-qa4.md)
- [0155 bootstrap boundary](0155-bootstrap-only-the-synthetic-demo-access-operator.md)
- [Issue #77](https://github.com/kaleemnadeem16/erp/issues/77)
