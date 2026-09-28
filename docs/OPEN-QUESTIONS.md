# Open Questions

Decisions not yet made. Each says what it blocks, so work can be sequenced around it.

**When a question is answered:** write an [ADR](decisions/), then delete the entry here.
Questions do not linger once resolved.

---

## Blocking

No open question now blocks finalising the Phase 3 Material Resolution / Assembly Preview ADR.
Q25 is resolved by ADR 0057; Q26–Q28 remain non-blocking follow-ups for organisation scope and
reusable approvals rather than reasons to pretend those capabilities exist in Phase 3.

Q14 (backdated stock receipts) was resolved on 2026-09-01 and its final reviewed
consolidation is
[ADR 0040](decisions/0040-control-backdated-stock-recalculation-and-corrections.md), with AVCO pool
scope remaining separately governed by ADR 0039.

Q20 (item divisibility and quantity increments) was resolved on 2026-09-25 — see
[ADR 0050](decisions/0050-item-specific-uom-conversion-and-divisibility.md). Its remaining package
boundary was subsequently resolved with Q17/Q19/Q22 by ADRs 0051–0053.

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

### Q23 — Variable-content and catch-weight packages
**Blocks:** Variable-content receipts and package/container tracking; not fixed-content Phase 3

Do first-generation package records assume fixed contained quantity, or must receipt record an
actual quantity per container (catch weight, variable-length rolls, partly filled drums)? The
answer affects item packaging, receipt capture, labels and later container balances.

[ADR 0050](decisions/0050-item-specific-uom-conversion-and-divisibility.md) deliberately does not
answer this: its item-specific conversion factor is fixed by definition and cannot represent a
quantity that varies per physical container. Industry practice (SAP Catch Weight Management)
models catch weight as two independently-recorded quantities per transaction — a count/handling
unit and an actually-measured weight/volume — rather than a smarter conversion factor. That is a
different mechanism, not an extension of ADR 0050's table, and remains this question's to design.

### Q24 — Phase 5 material exceptions
**Blocks:** Nothing in Phase 3; decide before job material issue/return/scrap design

Which exceptions must ship in the first Phase 5 slice: customer-supplied/free-issue material,
approved substitutes, recoverable scrap, reusable remnants and returnable containers? These must
not be collapsed into ordinary purchased consumption because ownership, value and availability
differ.

### Q26 — Organisational and record-level access scope
**Blocks:** Department/site/warehouse/assigned-record visibility and delegated administration

What organisational structure should access follow: departments, branches, sites, warehouses,
cost centres, project teams or a composable organisation-unit hierarchy? Define whether users may
see own, assigned, unit or all-company records and whether one user can act in several units.
Define bounded delegated administration—what a department head may assign to subordinates—and
routine cross-department assigned/task-scoped access without creating one role per department pair.
Spatie Teams currently scopes assignments by `company_id`; it is groundwork, not this record-scope
model. See [authorization.md](architecture/authorization.md#teams-and-organisational-scope).

### Q27 — Shared approval workflow contract
**Blocks:** The first configurable multi-stage approval implementation

Should Purchase Orders provide the first concrete workflow and then extract a shared approval
contract, or should a cross-module engine be built first? Decide the minimum shared semantics for
sequential/parallel steps, distinct approver counts, thresholds, expiry, delegation, rejection,
resubmission and policy-version snapshots. See
[approval-workflows.md](architecture/approval-workflows.md).

### Q28 — Purchase-order approval policy
**Blocks:** Introducing PO submission/approval before operational purchasing relies on issuance

Which PO approvals are required by amount, currency, department/site, supplier/category or other
risk; may the creator submit, may an approver also issue, how many independent approvers are
needed, and what happens on rejection or draft revision? Today any authenticated caller reaching
the endpoint can create and issue a PO; DRAFT → ISSUED has no approval fact.

### Q29 — Boolean query-string filters
**Blocks:** Nothing; it is a recurring per-screen workaround

The generated client serialises a boolean query parameter as the literal string `"true"`, which
Laravel's `boolean` rule rejects with a 422 (it accepts only `1`/`0`/`true`/`false` as real
booleans, not strings). The physical-count form, its detail screen and the Material Preview each
drop `filter[active]=true` and filter inactive rows in the browser instead, so a screen that
forgets shows an empty picker. Where should this be fixed once: a request-normalising middleware
that maps `"true"`/`"false"` query strings to booleans on the API, or the client's query
serialisation (which orval generates, so it cannot be hand-edited)?

---

## Answered

Answered questions live in [decisions/](decisions/) as ADRs. See the
[decision index](decisions/README.md) for the full list.

<a id="q17--phase-3-commercial-package-and-costed-source-proposal-scope"></a>
<a id="q18--audit-requirements-for-planned-quantity-adjustments"></a>
<a id="q19--default-ranking-for-source-and-pack-proposals"></a>
<a id="q21--phase-4-selling-price-strategy-baseline"></a>
<a id="q22--physical-container-granularity"></a>
<a id="q25--material-resolution-permissions-and-sensitive-fields"></a>

Q17, Q19 and Q22 were resolved on 2026-09-26 by
[ADR 0051](decisions/0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md),
[ADR 0052](decisions/0052-progressive-inventory-package-tracking.md) and
[ADR 0053](decisions/0053-explainable-inventory-package-selection-presets.md): Phase 3 proposes
mixed package use from internal inventory, physical tracking is progressive, and the first release
ships three explainable selection presets. Supplier replenishment remains Purchasing-owned.

Q18 was resolved on 2026-09-26 by
[ADR 0054](decisions/0054-risk-based-material-plan-variance-controls.md): every adopted variance
is audited, while versioned company/category/item policy escalates it to explanation, independent
approval or a hard block according to configured quantity, cost, waste and risk bands.

Q21 was resolved on 2026-09-26 by
[ADR 0055](decisions/0055-progressive-sales-pricing-and-controlled-formulas.md): Sales pricing is
independent from internal cost and tax, uses progressive SIMPLE/MANAGED/ADVANCED capabilities,
and advanced company formulas remain controlled, typed, versioned and explainable.

Q25 was resolved on 2026-09-26 by
[ADR 0057](decisions/0057-stable-material-resolution-capabilities-with-dynamic-assignment.md):
Material Resolution has stable action/field capabilities with company-configured roles and direct
grants; approval policy remains separate, while department and record scope remain Q26.
