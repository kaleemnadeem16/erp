# Open Questions

Decisions not yet made. Each says what it blocks, so work can be sequenced around it.

**When a question is answered:** write an [ADR](decisions/), then delete the entry here.
Questions do not linger once resolved.

---

## Blocking

None. Q14 (backdated stock receipts) was resolved on 2026-09-01 and its final reviewed
consolidation is
[ADR 0040](decisions/0040-control-backdated-stock-recalculation-and-corrections.md), with AVCO pool
scope remaining separately governed by ADR 0039.

Q1, Q2 and Q3 were resolved on 2026-08-10 — see
[ADR 0023](decisions/0023-retention-retainage-enabled.md),
[ADR 0024](decisions/0024-onboarding-base-currency-and-fiscal-year.md) and
[ADR 0025](decisions/0025-configurable-tax-engine.md).

---

## Non-blocking

Q6 (data import mechanism) and Q7 (permissions mechanism) resolved 2026-08-10 — see
[ADR 0027](decisions/0027-generic-column-mapping-import.md) and
[ADR 0028](decisions/0028-dynamic-namespaced-permissions.md). Q7's actual role/permission
*list* is still deferred to Phase 4, per those docs. Q9 (exchange rate source) resolved
2026-08-19 — see [ADR 0031](decisions/0031-manual-exchange-rate-entry.md). Q11 (stock valuation
method) resolved 2026-08-20 — see [ADR 0032](decisions/0032-per-item-valuation-method.md). Q12
(multi-supplier catalog) resolved 2026-08-20 — see
[ADR 0033](decisions/0033-item-suppliers-relationship.md).

### Q8 — Second customer profile
**Blocks:** Nothing yet — but shapes Phase 3 and the plugin design

The extensibility strategy in [extensibility.md](architecture/extensibility.md) warns against
over-abstracting before a real second customer exists. Knowing who that is — and how their
process actually differs — would let us validate the rate-dimension model against a real case
instead of a hypothetical one.

### Q10 — Sharing master data across a customer's own legal entities
**Blocks:** Nothing in Phase 1. Relevant once a customer wants more than one legal
entity/branch under one login.

`company_id` is the full tenant-isolation boundary
([GLOSSARY.md](GLOSSARY.md#company), [ADR 0004](decisions/0004-multi-tenancy-ready-schema.md)),
not a legal-entity subdivision within one customer. Today, "multi-entity support" means another
row in `companies`, isolated from its sibling exactly as strictly as from an unrelated tenant —
the only planned bridge is user membership across `company_id`s
([multi-tenancy.md](architecture/multi-tenancy.md)'s planned tenant-switching UI), not shared
data. There is no construct for sharing a specific record — e.g. a trading partner in
[parties.md](domain/parties.md) — between two legal entities of the same real customer without
also exposing it to unrelated tenants.

Surfaced while deciding [ADR 0030](decisions/0030-unified-party-model.md) (a global, unscoped
`parties` table was considered and rejected as the wrong fix for this). Options when it becomes
real: a parent "account" grouping above `company_id`; per-record cross-company sharing grants; or
accept duplication and reconcile via an external key. Not decided — no second legal entity
exists yet to design against.

### Q16 — Recording the posting actor on stock movements
**Blocks:** Nothing yet — decide before sales despatch/consumption endpoints call
`PostInventoryMovement::issue()` in Phase 4/5.

`stock_moves` records no acting user. Today every movement is reached through a source document
or workflow that carries its own actor — a receipt, reversal or QA disposition, an override
request, a posted physical count, an inventory-cutover commit, or a recalculation execution
(`RecalculationExecution.executed_by`) — so the audit trail composes. But a plain issue/transfer
posted by a future endpoint would identify its actor only through its source document's
conventions. Decide whether the movement boundary
should stamp `posted_by` itself (one column, uniform trail) or whether source-document actors
remain the single source of truth.

### Q17 — Whether location mode and default AVCO pool scope are governed policies
**Blocks:** Nothing today. Decide before the first real company configures itself, since
retro-fitting an audit trail cannot recover changes already made without one.

`UpdateInventorySettings` governs three policy groups (negative stock, backdating horizon,
recalculation): each needs an elevated permission, a mandatory reason, and writes an immutable
`InventoryPolicyChange` row. Two settings sit outside that treatment —
`inventory_location_mode` ([ADR 0041](decisions/0041-progressive-inventory-location-capabilities.md))
and `default_avco_pool_scope` ([ADR 0039](decisions/0039-configurable-avco-valuation-pools.md)) —
and `NegativeStockPolicyTest::test_non_policy_inventory_setting_does_not_require_elevated_permission_or_audit_reason`
pins that as deliberate.

Both are arguably as consequential as the governed three: the location mode changes what the
whole location model permits, and the default pool scope decides how every later AVCO item is
valued. Neither ADR says anything about permissions or audit either way. Surfaced by the
pre-deployment audit on 2026-09-09, which read the omission as a gap; the existing test reads it
as a decision. Governing them was implemented and then reverted, because changing a tested
decision needs an ADR rather than a silent fix. Purchasing's own over-receipt policy *was*
brought under governance in the same pass — it had no such counter-decision, and the phase's
"audit every consequential company-policy change" task explicitly asked for it.

---

## Answered

Answered questions live in [decisions/](decisions/) as ADRs. See the
[decision index](decisions/README.md) for the full list.
