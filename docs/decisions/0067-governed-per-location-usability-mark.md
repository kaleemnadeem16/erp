# 0067 — Let a company mark which locations count as usable stock, as a governed policy

- **Status:** accepted
- **Date:** 2026-09-29
- **Affects:** Inventory locations, Estimating's availability read and Material Preview, the location
  permission model, [inventory-packages.md](../domain/inventory-packages.md)

## Context

Estimating's advisory availability counted stock as usable only in `STORAGE` and `RECEIVING` locations
(`LocationType::holdsUsableStock()`), a deliberately conservative first rule that may under-report. It was
fixed by location type, so a company whose Returns bay holds good, resaleable stock could not have it counted
and would be told it was short.

How mature ERPs decide which stock counts, from their public documentation (found by search this session;
NetSuite's help page could not be opened directly, so its detail comes from search summaries):

| System | Where the answer lives | Who changes it / guard |
|---|---|---|
| Odoo | The location's *type* (internal counts; loss, scrap, customer do not; transit tracked apart) | Not established from native docs |
| NetSuite | A per-location "Make Inventory Available" checkbox | The ordinary Locations permission (Edit); refused while sales, transfer or work orders are open for the location |
| SAP | A per-storage-location planning indicator (customizing), and a separate stock *condition* (unrestricted / quality / blocked) | Configuration, not a day-to-day edit; a changed default does not rewrite existing records |
| Dynamics 365 | A stock *status* flagged blocking, independent of place | Warehouse-manager role maintains master data |

They agree that availability has two independent questions — where the stock is, and what condition it is in —
that no vendor adds a separate permission just for the flag, and that safety comes from a state guard or from
governance rather than from a special role. Condition is already handled here (stock held for QA release is
excluded separately), so this decision is only about place.

Editing a location was also authenticated-only: `UpdateLocationRequest`/`StoreLocationRequest` authorised any
signed-in user and `destroy` checked nothing, so anyone could change what planners see.

## Decision

1. **One nullable mark per location**, `locations.counts_as_usable`. `NULL` (every existing and new location)
   follows the type default; `TRUE`/`FALSE` overrides it either way. `Location::countsAsUsable()` is the single
   place that resolves it, and availability reads the mark, so a marked location changes the numbers and the
   evidence fingerprint. An inactive location or warehouse never counts, whatever its mark.
2. **It is a governed inventory policy.** Setting or clearing it needs `inventory.location-policy.manage` on
   top of ordinary location maintenance, a written reason, and writes an `inventory_policy_changes` row
   (`policy = location_usability`, with the location id and the old and new mark). Resubmitting an unchanged
   mark writes nothing. It is available only in `STRUCTURED` location mode (ADR 0041), and a company cannot
   return to `SIMPLE` while a mark exists.
3. **Locations are no longer authenticated-only.** Creating, editing and archiving a location need the new
   `inventory.location.manage` capability; reads stay open to signed-in users as shared reference data.
4. **The preview says why.** Each balance reports whether its location follows its type or was marked
   (`location_usable_source`), and the Material Preview shows "marked by your company as counting / not
   counting".
5. **Not built yet, on purpose:** a per-type company default table (a third layer few need), condition-based
   stock statuses beyond the QA hold, and NetSuite's guard against changing the mark while reservations are
   open — nothing is reserved today, so there is nothing to protect. That guard is required when reservations
   exist.

## Consequences

- **Positive:** A company with a good-stock Returns bay, or a damaged-goods overflow shelf, gets the right
  availability; a company that never opens location structure sees no change.
- **Positive:** Who changed what counts as usable, when and why is recorded like every other inventory policy.
- **Positive:** Editing a location is finally permissioned.
- **Negative:** No screen edits locations yet, so the mark is set through the API until a location screen exists.
- **Negative:** Warehouses and warehouse-location roles remain authenticated-only; they are the next gap.
- **Requires:** Reservations, when built, refuse to change a mark while they depend on it.

## Alternatives considered

**Keep the fixed rule** — rejected: it cannot be right for every company and offers no escape.

**Widen the fixed rule (e.g. count Returns)** — rejected: still one answer for everyone, and wrong for those who
keep damaged goods there.

**A company-wide "type → counts?" table** — deferred: a third layer that is hard to explain and rarely needed.

**A dedicated permission separate from the location-policy one** — rejected: `inventory.location-policy.manage`
already governs location structure with the same history and reason, and no surveyed ERP adds a permission for
this one flag.
