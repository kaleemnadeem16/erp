# Open Questions

Decisions not yet made. Each says what it blocks, so work can be sequenced around it.

**When a question is answered:** write an [ADR](decisions/), then delete the entry here.
Questions do not linger once resolved.

---

## Blocking

Q17–Q19, Q21 and Q25 block finalising the Phase 3 Material Resolution / Assembly Preview ADR. Their
shared research baseline and cross-module context are recorded in
[material-resolution.md](domain/material-resolution.md); answer them one by one rather than
letting a paint/coatings example silently define the generic model.

Q14 (backdated stock receipts) was resolved on 2026-09-01 and its final reviewed
consolidation is
[ADR 0040](decisions/0040-control-backdated-stock-recalculation-and-corrections.md), with AVCO pool
scope remaining separately governed by ADR 0039.

Q20 (item divisibility and quantity increments) was resolved on 2026-09-25 — see
[ADR 0050](decisions/0050-item-specific-uom-conversion-and-divisibility.md), which also narrowed
Q17 to the commercial-package/costed-proposal question below.

Q1, Q2 and Q3 were resolved on 2026-08-10 — see
[ADR 0023](decisions/0023-retention-retainage-enabled.md),
[ADR 0024](decisions/0024-onboarding-base-currency-and-fiscal-year.md) and
[ADR 0025](decisions/0025-configurable-tax-engine.md).

### Q17 — Phase 3 commercial-package and costed source-proposal scope
**Blocks:** The Material Resolution / Assembly Preview ADR and its final Phase 3 scope

[ADR 0050](decisions/0050-item-specific-uom-conversion-and-divisibility.md) settled that
item-specific UoM conversions belong in the model regardless of Phase 3's preview scope —
Purchasing already needs them to price box/each-style pack purchasing correctly, independent of
whether a preview ever proposes anything. What remains open is narrower: should Phase 3 also
introduce named, barcoded commercial package definitions and enough Purchasing-owned
supplier-pack/offer data to propose costed source allocations? Or should the preview stop at a
standard/planned quantity and current internal cost, with commercial packaging and supplier
offers deferred to Phase 4/5?

If included, the resolver may suggest cheapest, minimum-surplus or preferred allocations, but a
suggestion remains ephemeral. Only a user-adopted plan or issued-document snapshot becomes a
historical fact, and Phase 3 never reserves stock.

### Q18 — Audit requirements for planned quantity adjustments
**Blocks:** The standard → planned quantity contract in the preview and later quotation revision

Must every transaction-specific departure from the calculated standard carry a reason and actor,
or only adjustments above a threshold/policy? The system must preserve standard, planned and
actual independently; this question decides the audit burden, not whether the values are kept.

### Q19 — Default ranking for source and pack proposals
**Blocks:** Deterministic proposal behaviour if Q17 includes proposals in Phase 3

What is the default ranking: lowest estimated cost, least surplus, preferred supplier/package,
earliest availability, or a configurable weighted policy? The result must expose why it won and
allow a user selection without turning the rejected suggestions into history.

### Q21 — Phase 4 selling-price strategy baseline
**Blocks:** The boundary between Phase 3 cost output and Phase 4 customer price resolution

Which strategies ship first: maintained fixed/customer price lists, cost + markup/margin,
standard rate per work unit, manual override, or some ordered combination? Decide the default and
fallback rules, when dynamic prices recalculate, when maintained prices remain fixed, and which
price/quotation rounding policies apply. Internal cost must remain independent of customer price.

### Q25 — Material-resolution permissions and sensitive fields
**Blocks:** The Material Resolution / Assembly Preview ADR and preview API/UI contract

Which capabilities need independent permissions in the first preview: viewing internal cost and
supplier offers, running the preview, adjusting planned quantity, selecting a source/pack plan,
overriding cost, overriding selling price and viewing resolution history? Decide which fields a
basic estimator may see, whether cost/margin is restricted, and which adopted overrides require
approval rather than permission alone. Candidate names in
[material-resolution.md](domain/material-resolution.md#authorisation-impact) are illustrative
until this is answered.

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

### Q22 — Physical container granularity
**Blocks:** Nothing in Phase 3; decide before Phase 5 implements reservations and job issues

Must the first Phase 5 inventory-execution slice track individual opened drums, rolls, coils,
cartons or returnable containers, or is partial quantity by lot/location sufficient initially?
A commercial pack definition, a received traceability lot and a physical container are separate
concepts even when one receipt happens to create one of each.

### Q23 — Variable-content and catch-weight packages
**Blocks:** Nothing in Phase 3 unless Q17 includes these packages in source proposals

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

---

## Answered

Answered questions live in [decisions/](decisions/) as ADRs. See the
[decision index](decisions/README.md) for the full list.
