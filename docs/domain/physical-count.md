[← Domain index](README.md)

# Physical count

Reconciling recorded stock against what's physically on hand, and posting the difference as an
auditable, reviewed correction — not a silent edit of the book quantity.

**Governed by:** [stock.md](stock.md) — physical counts post through the same movement boundary
(`PostInventoryMovement`) as every other receipt/issue; nothing here bypasses it.

## Schema

```
physical_counts
  id, company_id, warehouse_id
  status                DRAFT | POSTED | CANCELLED
  reference NULLABLE
  created_by, created_at
  posted_by NULLABLE, posted_at NULLABLE
  cancelled_by NULLABLE, cancelled_at NULLABLE

physical_count_lines
  id, company_id, physical_count_id
  item_id, location_id, batch_id NULLABLE, serial_no NULLABLE
  book_qty                          -- snapshotted when the line is added
  counted_qty NULLABLE              -- the current working count; mirrors the latest entry
  counted_by NULLABLE, counted_at NULLABLE
  found_unit_cost NULLABLE          -- required only for a positive-variance line, set at posting
  posted_stock_move_id NULLABLE

physical_count_line_entries         -- immutable append-only recount trail
  id, company_id, physical_count_line_id
  counted_qty, counted_by, counted_at
```

## Workflow

1. **Create** the header against one warehouse (`inventory.physical_count.create`).
   *Blind counting (ADR 0083).* `book_qty` is shown only to someone holding `inventory.stock.view`, `inventory.physical_count.create` or
   `inventory.physical_count.post`; a counter holding only `inventory.physical_count.count` is never sent it and sees no variance.
2. **Add lines**, one per exact (item, location, batch/serial) identity. `book_qty` is snapshotted
   at this moment as `SUM(stock_layers.qty_remaining)` at that identity — the same physical-on-hand
   source of truth every valuation method already writes to (`stock_layers` exists for every
   receipt regardless of official method — see [stock.md](stock.md#valuation-by-method)), so this
   works identically for AVCO, FIFO and SPECIFIC without a method-specific query. The snapshot is
   an unlocked read: drift between it and the true position by post time is exactly what a count
   exists to catch, not something to prevent at add-line time. A book_qty of zero is valid and
   expected — the item may never have been recorded at this identity at all.
3. **Count** each line (`inventory.physical_count.count`) — the counter records a physical quantity
   only, never a cost. **Recounting a line replaces its working `counted_qty`**; the prior attempt
   is never overwritten, only superseded — every count, first or recount, appends an immutable
   `physical_count_line_entries` row, and the line always mirrors the latest one. A serial-line
   count is restricted to 0 (absent) or 1 (present).
4. **Post** (`inventory.physical_count.post`) — a distinct actor from whoever created the header,
   mirroring `ApproveInventoryCutover`'s own separation of duties. Requires every line counted.
   Zero variance is a no-op (no move, `posted_stock_move_id` stays null — that's how a reconciled
   line is distinguished from an uncounted one). A non-zero variance posts through
   `PostInventoryMovement` — the ordinary boundary, not a bespoke poster:
   - **Positive** (found more than recorded) posts as a receipt at a **reviewer-supplied
     `found_unit_cost`**, required per line and never inferred from AVCO's average, a FIFO layer,
     or any other plausible source — mirrors ADR 0037's own "never guess between plausible
     sources." The counter only ever records quantity; the poster supplies or approves the cost as
     part of posting.
   - **Negative** (found less) posts as an ordinary issue for exactly the recorded shortfall, with
     no `override_request_id`. That quantity is always covered by definition — it's a portion of
     the very `book_qty` the count snapshotted — so it only fails if something else moved this
     exact stock identity after the count was taken. `PostInventoryMovement`'s own
     insufficient-stock guard catches that race; this workflow doesn't re-derive it, and doesn't
     invoke ADR 0037 override authority to work around it.

   Posting is one transaction: either every line's variance posts, or none does. Posting an
   already-posted count is a no-op, not an error — matching `ApproveInventoryCutover`/
   `CommitInventoryCutoverOpeningState`'s own idempotent-return convention.
5. **Cancel** (optional) — abandons a draft count with no postings. A posted or already-cancelled
   count cannot be cancelled again.

## Design choices worth naming

- **Cost basis for found stock is always explicit, never automatic**, uniformly across AVCO/FIFO/
  SPECIFIC. A found-more line without a supplied cost refuses to post — there is no "just use the
  AVCO average" shortcut, even though AVCO technically has one available. One rule for all three
  methods rather than a method-specific carve-out.
- **A recount replaces, it doesn't compete.** One `counted_qty` per line drives variance and
  posting, always the most recent entry. A prior miscount doesn't need to be resolved by a reviewer
  picking "which count wins" — the model already answers that — while the full attempt history
  stays immutable and queryable for audit.
- **No new stock-movement machinery.** A physical-count adjustment is not a new `movement_kind`.
  A positive variance is shaped exactly like a receipt (`NULL` → location); a negative variance
  exactly like an issue (location → `NULL`) — see [stock.md](stock.md#move-types). `source_type =
  'PHYSICAL_COUNT_LINE'` is what distinguishes it, the same way a customer return is distinguished
  from an ordinary receipt.

## User interface

The physical count HTTP surface is exposed per
[phase-2-purchasing-stock.md](../phases/phase-2-purchasing-stock.md#close-out): the header
(`PhysicalCount`) is create/read-only over HTTP (`POST`/`GET /api/v1/physical-counts`,
`GET /api/v1/physical-counts/{id}`) and every state change is a named action —
`POST .../lines` to add a line, `POST /api/v1/physical-count-lines/{id}/count` to record or
recount one, `POST .../post` and `.../cancel` on the header. `PhysicalCountController` reshapes
`post`'s `found_costs` (a list of `{physical_count_line_id, unit_cost}` pairs — not a
line-id-keyed object, which has no sound OpenAPI/generated-client type) into the
`id => cost` array `PostPhysicalCount` actually expects.

The React screens (`/physical-counts` list, `/physical-counts/new`, `/physical-counts/:id`
detail) are the first document-workflow UI in the frontend, distinct from the master-data
list/detail/print foundation [items.md](items.md#user-interface) established: the detail screen
is the count's whole working surface while `DRAFT` — add a line, record its quantity inline,
supply a found cost for anything counted higher than book, then post or cancel — and every
mutation refetches the count rather than patching local state, since variance and "is every line
counted" are always derived server-side.

Count reads remain available as a quantity-only company record. `found_unit_cost` is omitted
from included lines unless the reader can post a physical count or has
`inventory.valuation.view`, including after posting and in list responses. The poster needs
that economic evidence to supply and verify the explicit positive-variance cost; a counter
only records quantity. The detail screen follows the same projection: create can add/cancel,
count can record/recount, post can supply found costs and post (subject to the distinct-actor
rule), and a valuation viewer can inspect the cost without gaining a posting action. The New
route requires create even when a user can otherwise read counts. This applies the existing
[valuation visibility decision](../decisions/0073-separate-inventory-valuation-visibility-from-stock-operations.md)
to the physical-count workflow; it does not change how the found cost is chosen.

### Who may read and act on a count (2026-10-06)

Listing or opening a count needs **any one of** `inventory.physical_count.create`, `.count` or
`.post`, `inventory.stock.view` (read-only audit of positions, ADR 0083) or
`inventory.valuation.view` (audit of the cost effect). Before this, any signed-in user could read
every count's items, locations and counted quantities. What each reader sees inside a line is still
projected separately: the book quantity only for set-up, posting or stock-position holders (blind
counting), and the found cost only for posters and valuation readers. Item-level availability and
ledger-only holders are refused. Every write now also checks its capability at the request layer
(create, add line `.create`; count a line `.count`; post `.post`; cancel `.create`, which gained a
request class), answering 403 instead of the Action's 422; the Actions keep their own checks.

## Related

- [stock.md](stock.md) — the movement/valuation model this posts into
- [negative-stock.md](negative-stock.md) — ADR 0037, the authority a count-driven write-off
  deliberately does *not* invoke
