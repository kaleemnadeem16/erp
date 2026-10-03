# 0082 — Reconcile package balances with stock operations

- **Status:** proposed
- **Date:** 2026-10-03
- **Affects:** Inventory, receipt/QA/reversal integration, physical counts, material availability

## Context

ADRs 0052/0053 establish progressive tracking and advisory selection. ADR 0080 implements fixed
named package definitions and purchasing evidence, but does not establish their physical on-hand
state. The owner asked to start this design and confirmed that unopened counts plus combined
open/loose quantity are sufficient for the first version; individual opened-container identity
is not required. This confirmation settles granularity, not the remaining integration policies.

The implementation currently has only QUANTITY_ONLY stock. Existing stock cannot prove its sealed
composition. Opening a box of sealed bottles differs from opening the bottles themselves. A
package's physical contents cannot disappear from stock merely because its outer packaging opens.

## Proposed decision

Extend ADR 0052 with PACKAGE_BALANCE: count sealed definitions and group open/loose quantity within
the same company, item, location and required traceability/availability scope. Preserve the stock
movement/valuation engine as the quantity and cost authority. Implement the delivery gates in
[the plan](../phases/package-balance-delivery.md); this proposal does not activate tracking or
authorise a new valuation method. An inactive evidence-storage foundation and authorised checkpoint writer/reader now exist.
The reader enforces inventory.package-state.view; no operational stock writer or activation
setting is added. An internal counted-evidence writer now uses
existing physical-count creation authority; it does not activate tracking.

### 1. Physical and availability state are separate

SEALED and OPEN_LOOSE describe physical form. QA hold, location usability and applicable batch
restrictions describe availability. A sealed package can be held; accepting a hold does not open
it. Never merge different batches, locations or hold provenance into a single loose bucket.
Grouped leftovers do not prove that their contents have been physically mixed.

At every enabled stock scope:

```text
sum(sealed_count × snapshotted_content) + sum(open_loose_quantity) = physical on-hand
```

Held stock is included in physical on-hand but excluded from usable availability. Cost is never
stored on a package definition/balance as a second valuation truth. Package quantities must be
exactly representable at the stock ledger's four-decimal scale; a definition with finer content
cannot enter physical tracking by silent rounding. Whole sealed-package counts are required even
when purchasing permits fractional equivalents.

### 2. Distinguish unpacking from opening contents

Unpack one box containing 20 sealed 100 mL bottles: subtract one sealed 2 L box and add 20 sealed
100 mL bottles. Do not also keep the box in on-hand or create 2 L loose liquid. Open one bottle:
subtract one sealed bottle and add 0.1 L OPEN_LOOSE. Both are audited, quantity/value-neutral
operations at the same location and traceability scope.

If no inner definition exists, the operator explicitly declares the contents open/loose when
breaking the package. The system never invents inner packages or assumes the packaging was
resealed. Grouped loose stock does not record opened-container counts, cleanliness or contents
per vessel. General repacking, new seal certification and returnable packaging remain deferred.

### 3. Receipt evidence is physical confirmation

An order's package is an agreement, not proof that stock arrived sealed. Posting a tracked
receipt requires an observed split: whole sealed packages plus actual open/loose quantity.
Physical content must equal received stock quantity; the agreed factor and independent price
basis remain frozen. A damaged/open delivery can preserve the commercial package count while
recording different physical composition. No inventory package state is created for draft receipts.

### 4. One transactional stock boundary

Receipt, issue, transfer, reversal, QA scrap/return and count adjustment update enabled package
state inside the stock operation's existing transaction. No controller or seeder writes a second
stock ledger. Transfer carries the declared sealed/open composition to the destination; a scalar
quantity must not silently consume whichever package happens to be available.

Opening/unpacking has no stock move or valuation entry because quantity, location and value do
not change, but has immutable package event evidence. Consuming contents is a later stock issue;
job issue/reservation screens remain Phase 5. No consumption is disguised as a packaging change.

The integration spike must prove compatibility between physical choices and current layer
allocation, FIFO/AVCO valuation, QA attribution and receipt reversal. A selected package must
not silently change the valuation method, assign a false receipt cost or bypass held stock.
Do not decide a layer/provenance schema before this is demonstrated with real posting Actions.

The [compatibility spike](../domain/package-balance-integration.md) demonstrates that a counted
sealed package can span multiple cost layers. Proposed physical cohorts therefore need separate
layer contribution quantities, including an explicit AVCO allocation vector; a single layer
foreign key on each sealed balance is insufficient. Counted bookkeeping attribution must not
claim verified historical receipt provenance. The test-only contribution contract now covers whole-count selection, per-layer allocation
equality, QA usability and destination remapping. Real QA split evidence and transactional
package projections remain enforcing implementation work.

### 5. Enable only with a reconciled opening state

The implemented [policy foundation](../domain/package-tracking-policy.md) keeps QUANTITY_ONLY
as the only persistable mode and pins creation-time item/category/company resolution under ADR 0052.
Model/database gates block advanced modes; no activation setting is exposed.
Existing items require a physical count of sealed definitions and open/loose stock, a reason,
actor and a reconciled checkpoint before activation. Recheck stock under locks when committing:
a count made before another receipt/transfer cannot activate from a stale total. Do not rewrite
historical receipts or infer sealed packages by division.

A non-zero discrepancy uses the existing physical-count adjustment/approval path before package
activation. A negative ledger balance cannot be represented as negative sealed packages or fake
loose stock. Active shortage exceptions must be resolved before enabling. Downgrades and the
future CONTAINER_TRACKED upgrade follow ADR 0052's reconciliation/no-detail-loss controls.

### 6. Audit, concurrency and access

Store immutable operation evidence: actor, reason, company/scope, before/after composition,
snapshotted package identity/content, linked stock document when present and request identity.
Balances are rebuildable projections. Corrections append compensating evidence after checking
later operations; they never edit event history or manufacture unopened packages.

Use stable scope locks, deterministic multi-location lock order, unique idempotency identity and
payload equality on retries. Quantity/cost/package updates either all commit or all roll back.
Backdated corrections must preserve both timelines; until the adapter is proved, refuse the
unsupported tracked operation rather than commit one side. Production activation requires every
existing writer to be integrated or explicitly blocked for tracked items with a usable explanation.

The internal history reader now enforces inventory.package-state.view. Remaining proposed
distinct Inventory capabilities: open/unpack and tracking-policy
management. Existing receipt, transfer, count, QA and valuation permissions remain separate.
Register final names with the enforcing slice, assign dev personas and follow the administrator
rollout procedure. Opening permission is not issue/scrap/QA authority. Cost fields still require
Inventory valuation access or the consuming module's existing server-side projection contract.

### 7. Proposal precision follows available evidence

Estimation continues to read advisory snapshots, without reservation or posting. Exact sealed
choices require reconciled balances in usable scopes. Grouped remnants do not support a claim
about a particular can's residue or shelf life. Expiry/FEFO requires real recorded expiry evidence;
the current schema lacks that evidence and must report the criterion unavailable.

Container identity, catch weight, manufacturing repack, package reservations and expiry lifecycle
are not smuggled into this release. Serial/SPECIFIC, negative-stock and backdated activation
eligibility must be stated explicitly after the posting compatibility spike, not assumed.

## Consequences

- **Positive:** the owner gets exact package-type counts without scanning every vessel; nested
  unpacking preserves unopened inner packs and monetary history.
- **Negative:** enabling tracking affects every stock writer, not just receipt forms. Existing
  balances need counted opening evidence. Grouped remnants cannot answer container-specific questions.
- **Requires:** integration and reconciliation tests, atomic posting, permission rollout, demo
  scenarios and an operational journey before activation can ship.

## Alternatives considered

**Infer sealed packages from litres** — rejected: 20 L can have many physical arrangements.

**Treat every unpack as loose liquid** — rejected: opening an outer carton need not open its bottles.

**Count outer and inner packages together** — rejected: double-counts the same stock.

**Make individual container IDs mandatory now** — rejected for this release because the owner
confirmed grouped leftovers are sufficient. ADR 0052 retains that later capability.

**Ship receipt-only package balances** — rejected as operationally incomplete: transfers, counts
or reversals would immediately invalidate exact availability claims.

**Let package selection silently replace FIFO costing** — rejected: physical evidence must not
silently redefine the accepted valuation method. The integration seam needs proof before schema.

## Research basis

Question: how should aggregate sealed/open evidence, nested unpacking and partial movement stay
consistent with authoritative stock without requiring individual container identities?
Reused ADRs 0052/0053/0080; checked primary vendor documentation on 2026-10-03:

- [Odoo packaging](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/product_management/configure/packaging.html)
  distinguishes item-specific contained quantity from physical stock proof and exposes full or
  partial packaging reservation. Its definition/reservation concepts do not by themselves settle
  our aggregate open/loose lifecycle. The packages page timed out on direct access; no unsupported
  claims about its unpack implementation are used here.
- [Dynamics license-plate build](https://learn.microsoft.com/en-us/dynamics365/supply-chain/warehousing/license-plate-build)
  supports nested identities and moves their contents together. Stock belongs to leaf plates;
  parents group them. This supports our no-double-counting rule, but its identified-container
  workflow would exceed this owner's chosen granularity.
- [SAP handling units](https://help.sap.com/docs/SAP_ERP/248c3cdd7e6548999a7f5b95118f4522/4134bb536b13b44ce10000000a174cb4.html)
  and [HU stock consistency](https://help.sap.com/docs/SAP_S4HANA_CLOUD/32da8359c8ee4e8b8e8c5e15cacba5aa/deb44d96aa234bada7d5893f1802ab63.html)
  distinguish packing history from movements that maintain physical inventory consistently.
  Ordinary quantity-only transfers can leave HU contents uncertain. Our consequence is atomic
  integration for every writer; SAP's mandatory HU-location model is not copied.

Convergence: definition and on-hand evidence differ; nesting must avoid double-counting;
movements must preserve physical attribution. Divergence: vendor identity-oriented models impose
more capture than aggregate balances. Our grouped model is a project-specific inference from
those invariants and ADR 0052, not a vendor feature reproduced verbatim. Remaining uncertainty is
the concrete valuation/QA/correction adapter and supported activation matrix, to be proved next.

## Related

- [ADR 0052](0052-progressive-inventory-package-tracking.md)
- [ADR 0053](0053-explainable-inventory-package-selection-presets.md)
- [ADR 0080](0080-packaged-purchasing-and-independent-price-basis.md)
- [Inventory packages](../domain/inventory-packages.md)
- [Delivery plan](../phases/package-balance-delivery.md)
- [Research gate](../architecture/research-and-design.md)
