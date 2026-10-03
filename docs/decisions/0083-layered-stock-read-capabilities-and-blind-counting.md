[← Decisions](README.md)

# 0083 — Layered stock-read capabilities, and blind counting by role

- **Status:** accepted
- **Date:** 2026-10-03
- **Affects:** Inventory read APIs (overview, layers, AVCO pools, ledger, physical counts), the Inventory screens and their navigation,
  company roles, [access audit](../architecture/access-security-audit.md) item A6. Supersedes the quantity-read paragraph of
  [ADR 0073](0073-separate-inventory-valuation-visibility-from-stock-operations.md); its valuation boundary stands.

## Context

ADR 0073 separated Inventory *valuation* from stock operations and chose to keep quantity and identity reads open to every
authenticated company user. That left three problems, recorded as A6 in the access audit:

- **Estimating's availability capability could not hide anything.** `estimating.inventory_availability.view` withholds stock
  availability from the material preview, but the same quantities were one call away through the Inventory API.
- **The screens and the API disagreed.** The SPA shows the overview and ledger only to people holding some inventory capability; the
  API served them to everyone.
- **A company could not separate duties.** "Sales may see what can be promised" and "only the warehouse sees locations and who moved
  what" were not expressible, and a physical counter always saw the book quantity, so no count could be independent.

## Decision

Three separate read capabilities, each independent of `inventory.valuation.view` (value and cost, unchanged):

| Capability | Grants | Does not grant |
|---|---|---|
| `inventory.availability.view` | The item-level summary: on hand, available, QA-held, on order, shortage (and reserved, once reservation exists) | Where stock is, batches/serials, movements |
| `inventory.stock.view` | The position: balances by warehouse, location, batch and serial; stock layers; AVCO pool quantities; the book quantity on a count. **Also gives the availability summary** — the wider layer covers the narrower, so a warehouse role does not list both | Movements; value or cost |
| `inventory.movement.view` | The stock ledger: who moved stock, when, from which source document | Any balance or position; value or cost |

- **Enforcement.** The overview and its summary need availability (or stock); stock layers and AVCO pools need stock; the ledger and a
  move need movement. The Form Request refuses with 403 before any include, filter or sort is read, so a deeper layer cannot be
  reached by a query parameter. The overview offers no location or warehouse filter. Valuation fields stay behind
  `inventory.valuation.view` exactly as ADR 0073 set them.
- **Documents are not stock reads.** A PO's outstanding quantity or a receipt's received quantity follows that document's permission.
  These capabilities cover balances and history.
- **Blind counting, by role.** A physical-count line's `book_qty` is shown only to someone who holds `inventory.stock.view`,
  `inventory.physical_count.create` or `inventory.physical_count.post`. A counter holding only `inventory.physical_count.count` is
  never sent it, and the screen drops the Book and Variance columns (a variance would give the figure away). There is no per-count
  blind setting; it is a future extension if needed.
- **Estimating stays separate.** `estimating.inventory_availability.view` keeps meaning "stock context inside an Estimating plan". It
  grants no Inventory screen, and the Inventory capabilities do not expose the material preview.
- **Rollout.** The release registers the capabilities and, in a migration that runs before the API checks bite, grants all three to every
  role and every direct assignment that holds a capability which already showed these screens (item/category manage, physical-count
  create/post, cutover, negative-stock, recalculation, valuation view). Nobody who used the screens is locked out. **One deliberate
  exception:** `inventory.physical_count.count` alone does not trigger a grant — an item-level on-hand figure would hand a blind counter
  the answer — so pure counters lose the overview and ledger screens they could open before and keep the count screens. A person holding
  count plus any other trigger is still granted through it. The administrator receives the capabilities through the normal
  `permission:register` and `erp:sync-admin-permissions` steps of the deploy job.
- **Record scope** (warehouse- or site-level reads) is not part of this decision; it waits for [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope).
  The capabilities are company-wide until then.
- **Out of scope, separate follow-up:** reads of negative-stock override requests, which behave like workflow/governance access.

## Consequences

- **Positive:** a small company gives staff one role carrying availability and stock; a larger one gives sales availability only,
  the warehouse stock and movement, auditors movement without value, and counters nothing. The Estimating capability is now a real
  boundary for its own surface, and the API matches what the SPA already showed.
- **Negative:** three more capabilities to assign; pure counters lose two screens; an existing integration reading these endpoints
  with a user that holds none of the triggers now gets 403 and needs a grant.
- **Requires:** the grant migration ships in the same release as the enforcement; verify effective access for representative
  warehouse, sales, counting and finance users in staging; any new stock-read surface must pick a layer and add its own
  allowed/denied tests (see `StockVisibilityTest`).

## Alternatives considered

**Keep quantity reads open and only document the Estimating capability** — rejected: a larger company then cannot restrict stock
levels or the ledger at all, and counts cannot be independent.

**One `inventory.stock.view` for every stock read** — rejected: sales needs "can I promise this" without locations, and the ledger
exposes who moved what and from which document, which is audit detail, not balance.

**A company setting "stock visibility: open / restricted"** — rejected: the authorisation model has stable capabilities that
companies assign, not per-module modes, and a capability that means different things per company is hard to audit.

**Merge `estimating.inventory_availability.view` into the Inventory capability** — rejected for now: it would need a grant migration
and changes ADR 0057's capability table for no gain; the two now guard different surfaces.

**A per-count blind/non-blind flag** — deferred: role-based blindness covers the need today without another setting.

## Research basis

Question: how do mature ERPs separate what is in stock from where it is and how it moved, and who may see book quantity while
counting? Checked 2026-10-03 against official documentation. Odoo's inventory documentation and access-rights notes show different
quantity fields for Inventory User and Administrator, supporting more than one read level. SAP's stock overview is organised by company
code, plant and storage location, and its physical-inventory apps separate count entry (warehouse clerk) from document management (inventory
manager). Business Central distinguishes quantity on hand from projected availability. The documentation searched did not state a
sales-versus-warehouse quantity split or a blind-count feature in primary sources, so those parts of the design are this project's
reasoning, not vendor fact. The ADR 0073 research on valuation versus operational visibility still applies.

## Related

- [ADR 0073](0073-separate-inventory-valuation-visibility-from-stock-operations.md), [ADR 0057](0057-stable-material-resolution-capabilities-with-dynamic-assignment.md)
- [Stock](../domain/stock.md), [Physical count](../domain/physical-count.md)
- [Access and sensitive-data audit](../architecture/access-security-audit.md)
