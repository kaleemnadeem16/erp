[← Roadmap](README.md)

# Grouped package tracking — screens and API (design)

Design pass 2026-10-08. **First slice built 2026-10-08** (item card, activation wizard, receipt composition panel, issue dialog, the read/draw/issue/checkpoint/composition endpoints and stable `reason_code`s; see the status table below); the rest is still design. The backend (ADRs 0090–0093) is internal Actions only: no HTTP route records a receipt composition, issues, transfers
or activates, and the only package read is the replay Action. Use this with [user-interface.md](../architecture/user-interface.md),
[ui-building-blocks.md](../architecture/ui-building-blocks.md) and [api-conventions.md](../architecture/api-conventions.md); container-level screens are out of scope.

## Principles

- **Mode decides what appears.** A quantity-only item keeps today's simple screens: no package control, column or message anywhere. Package controls render only when
  the item's resolved mode is grouped; the *start* of grouped tracking is an advanced, permission-gated disclosure on the item.
- **Total and breakdown, always together.** Wherever stock is shown for a grouped item: `59 L = 10 sealed 5 L cans + 9 L loose`. The ledger quantity stays the headline.
- **Warehouse language, not accounting.** No layer, FIFO or AVCO wording on these screens. Cost appears only where a person must supply it (found stock) or hold the
  right to see it (`inventory.valuation.view`).
- **Opening is part of the task.** In issue and transfer the person says what they take ("need 12 L"); the screen proposes whole cans, loose litres and "open 1 can for
  the last 2 L" as one draw. No separate "open" operation to remember.
- **The server proposes, the person confirms.** Every draw, count and reversal arrives pre-filled from a server suggestion; changing it re-validates against live stock.
- **Actionable blockers.** Domain refusals are 422s with free text today. Add a stable `reason_code` beside the message (`NEEDS_COUNT_FIRST`, `STALE_EVIDENCE`,
  `DRAW_NOT_ON_HAND`, `UNTRACKED_LEVEL`, `LAYER_CONSUMED`, `SUPPLIER_QA_UNSUPPORTED`, `NEEDS_OBSERVED_COMPOSITION`) so the UI can offer the fix ("Count this location") instead of parsing text.

## Shared pieces

**New components** (in `features/package-stock/`, built on existing blocks):

| Component | What it is |
|---|---|
| `PackageBreakdown` | read-only chips: `10 × 5 L can · 9 L loose · 59 L total`; compact on mobile, "stale" badge when evidence is not fresh |
| `PackageDrawEditor` | rows for sealed cans (stepper, max = on hand), loose litres (decimal, max = on hand) and an "open a can for the rest" toggle that shows what stays loose; live total vs the wanted quantity |
| `useItemPackageMode(item)` | the one gate: `quantity_only` / `grouped`; every package control reads it |

**Read API (missing)**

| Endpoint | Backed by | Notes |
|---|---|---|
| `GET /api/v1/package-balances?item_id&location_id&batch_id` | `ReplayPackageCheckpoint` on the latest evidence per scope | per scope: ledger total, sealed rows `{packaging_id,name,count,content}`, loose, `fresh`, `reasons[]`, head operation id. Needs `inventory.package-state.view`; filtered/404 by warehouse scope like stock layers |
| `GET /api/v1/items/{item}/package-tracking` | item + activation + tracked levels | mode, activated_at/by, tracked levels (names), `can_activate` |
| `POST /api/v1/package-draws/suggest` | new `SuggestPackageDraw` (pure) | input `{item_id, location_id, batch_id?, quantity}`; returns draw lines + the resulting breakdown + warnings. Default policy: loose first, then fewest whole cans, then open one can for the remainder (ADR 0053 `MINIMISE_WASTE`) |

Item resources gain `package_tracking: {mode, tracked_levels[]}` so lists and forms can gate without a second call.

## 1. Item package-tracking settings and activation

- **Where:** Item detail → a new "Package tracking" card (below purchasing panels) and, for the item's packages, the existing item packaging editor. Company-wide
  defaults stay in Settings → Inventory and remain quantity-only (the gate in ADR 0052/0091 is unchanged).
- **User sees:** quantity-only item: one line "Tracked by quantity only" and, for holders of `inventory.package-state.activate`, a "Start grouped tracking…" button.
  Grouped item: mode, activated date and person, tracked levels (`5 L can`), and the per-location breakdown with freshness.
- **Activation wizard** (`WizardShell`, page): ① *Readiness* (`preview`): blockers in plain words — serial/SPECIFIC item, open shortage, cutover in progress, QA-held stock;
  ② *Tracked levels*: list of the item's sealed packages, innermost pre-ticked, outer boxes optional, "tracking a box tracks the cans inside"; ③ *Opening count*, one
  card per location/batch holding stock: sealed cans per tracked level + loose litres, with the ledger total shown beside it and a green tick only when they match;
  ④ *Confirm*: reason, "this cannot be undone for now", Activate.
- **Suggested:** nothing is guessed for sealed counts (a count must be observed); the loose field shows the live remainder (ledger litres minus the cans entered) so the person
  only types cans. Tracked levels default to the innermost definition.
- **Validation:** counted total must equal ledger litres exactly; stale count (stock moved while counting) → "Stock changed, recount"; each blocker with its code.
- **API:** `GET/POST` above plus `POST /api/v1/package-checkpoints` (exposes `RecordPackageCheckpoint`, one per scope) and `POST /api/v1/items/{item}/package-tracking/activate`
  (`ActivateGroupedPackageTracking`, body: request_key, reason, checkpoints[], tracked_levels?). Preview = `PreviewGroupedActivation`. All three Actions can be exposed
  directly; each needs a FormRequest checking the capability and the Action re-checks.
- **Permissions/scope:** `inventory.package-state.activate` + `.view` (+ `inventory.physical_count.create` for the opening count). A person limited to their units
  counts only their warehouses; activation needs every stocked scope, so an unrestricted person is required (the screen explains it).
- **Mobile:** wizard steps stack; each location is a collapsible card; steppers instead of typed numbers.
- **Hidden for quantity-only:** everything except the single advanced line and button.

## 2. Package-aware goods receipt

- **Where:** the existing Goods receipt create/edit screens (`features/goods-receipts`), per line, and the detail screen (read-only observed composition).
- **User sees:** for a grouped item's line a "What arrived" panel under the received quantity: `Delivered: 12 L (agreed: 2 boxes)` and the observed composition
  rows: sealed cans (stepper) and loose litres, live total vs received quantity. A line of a quantity-only item shows nothing new.
- **Suggested:** the commercial package converted to tracked levels (2 boxes of 4 cans → 8 sealed cans), editable; loose = remainder. If boxes are untracked the
  UI states "boxes are counted as the cans inside".
- **Validation/blockers:** composition must add up to the received quantity; QA-required line → "Not supported for package-tracked items yet" (`SUPPLIER_QA_UNSUPPORTED`);
  untracked level → "This item doesn't track cartons — enter the cans inside"; destination holds stock without current evidence → "Count this location first" with a link;
  open shortage → settle first. Posting is blocked until the panel is complete (a grouped line without composition cannot post).
- **API:** missing `PUT /api/v1/goods-receipt-lines/{line}/package-composition` (`RecordReceiptComposition`, draft only, reason required) and the composition included in
  the goods-receipt resource; posting (`PostGoodsReceipt`) is already routed. A `GET …/package-composition/suggest` can reuse `SuggestPackageDraw`'s sibling.
- **Permissions/scope:** existing `purchasing.goods-receipt.create`/`post`; recording needs `purchasing.goods-receipt.create` (as the Action checks). Location scope as today.
- **Mobile:** the panel is a full-width card per line; the stepper is the primary input.

## 3. Package-aware issue

- **Where:** new **Inventory → Issue stock** screen (there is no issue screen today; job issue belongs to Phase 5, which will reuse this editor), also reachable as an
  "Issue" action on a grouped item's package-stock card. Presented as a `RecordSheet` from the item card, a page from the menu (per `presentation.ts`).
- **User sees:** item + location (+ batch) pickers, the current breakdown, a "How much do you need?" litres field, and below it the proposed draw:
  `Take 5 L loose · 1 sealed can · open 1 can and take 2 L` → `Leaves: 1 can + 3 L loose · 47 L total`. "Adjust" opens the `PackageDrawEditor`.
- **Input:** wanted litres (or edit the draw), reason/reference (job or free text). Nothing else; opening is shown as a line in the draw, never a separate step.
- **Suggested:** `suggest` on every quantity change; editing rows shows the resulting breakdown and flags a total that differs from the wanted quantity.
- **Validation/blockers:** more than on hand ("only 12 L here"); a draw not on hand after someone else issued (`DRAW_NOT_ON_HAND`, "stock changed — review"); stale evidence
  → "Count this location"; opening needs `inventory.package-state.open` (if missing the suggestion avoids opening and says so); backdating is not offered.
- **API:** missing `POST /api/v1/stock-issues` (`IssuePackagedStock`; idempotent on `request_key`; returns the move and the new breakdown). Quantity-only items would need
  a plain quantity form of the same endpoint, which also needs a **new capability** (no stock-issue permission exists) — a decision for the owner; the first slice is grouped only.
- **Permissions/scope:** `inventory.package-state.issue` + `.view` (+ `.open` for openings); warehouse scope: only the person's units' locations are offered.
- **Mobile:** the proposed draw is a stack of large lines; "Adjust" is a bottom sheet; one primary "Issue 12 L" button.
- **Quantity-only:** (later) one litres field and the reason; no breakdown, no draw.

## 4. Package-aware transfer

- **Where:** new **Inventory → Transfer stock** (also an action on the package-stock card); same sheet/page rule.
- **User sees:** from-location breakdown, to-location picker, wanted litres, the proposed draw (same editor), and a two-sided result: `Karachi: 1 can + 3 L loose` →
  `Lahore: +1 can + 2 L loose`. A line states plainly that whole cans arrive sealed and anything opened arrives loose.
- **Input:** destination, quantity/draw, reason. The destination's own current breakdown is shown read-only.
- **Validation/blockers:** destination holds stock without current evidence (`NEEDS_COUNT_FIRST`, link "Count Lahore"); same-location; stale source; same draw errors as issue.
- **API:** missing `POST /api/v1/stock-transfers` (`TransferPackagedStock`; the destination predecessor is resolved server-side from the destination's latest evidence, null when
  empty). **Permissions/scope:** `inventory.package-state.transfer` + `.view` (+ `.open`); **both** locations must be in scope. **Mobile:** two stacked cards, destination first.

## 5. Package-aware physical count

- **Where:** the existing Physical count create/detail screens (`features/physical-counts`).
- **User sees (counter, blind):** per line a composition entry: sealed cans per tracked level (steppers) and loose litres; no book figure is shown to a counter holding
  only `.count`. **Reviewer/poster** sees per line the book breakdown, the counted breakdown and a **verdict badge**:
  `Same litres, different cans — composition fix, no stock movement` (neutral) vs `1 L short — stock adjustment of −1 L` (amber) vs `+1 L found — needs a cost` (amber).
- **Input:** counted composition; for found stock a unit cost (existing `found_costs`); poster reason where relevant.
- **Suggested:** nothing for counters (blind). For the poster, found-stock cost suggestion = the item's current average/last receipt cost, editable, shown with the person's cost right.
- **Validation/blockers:** tracked levels only (`UNTRACKED_LEVEL`); a **stale** line (stock moved after capture) turns red with "Refresh and recount" (calls refresh, clears the counted
  composition, count stays draft) and blocks posting the whole count, naming the lines; creator ≠ poster as today; recount capability required to post a grouped line.
- **API:** exposable now with small changes: line resource gains `grouped`, `counted_composition`, `book_composition` (poster only), `stale`; `POST physical-count-lines/{line}/count`
  accepts `composition` for grouped lines (`RecordPhysicalCountComposition`); new `POST physical-count-lines/{line}/refresh` (`RefreshPhysicalCountLine`); `POST physical-counts/{id}/post`
  unchanged shape, returns per-line outcome. **Permissions:** `.count` (counter), `.create` (lines, refresh), `.post` + `inventory.package-state.recount` + `.view` (poster); warehouse scope per count.
- **Mobile:** counting is the mobile use case: one line per screen, big steppers, a running litres total, swipe to the next line.
- **Quantity-only:** unchanged single quantity per line.

## 6. Package-aware goods-receipt reversal

- **Where:** the existing Goods receipt detail → "Reverse receipt…" dialog (today a reason box).
- **User sees:** per line "Returning to supplier": the **observed composition as the default** (`2 sealed cans + 2 L loose`), beside **what is on hand now** (`4 cans, 12 L loose — 1 can opened`),
  with the draw editor to confirm or change; a coverability tick/cross and the total vs the received quantity. Lines of quantity-only items show only the reason.
- **Suggested:** the default draw; if it is no longer on hand, the nearest coverable draw is proposed and flagged "Stock has changed form — please confirm".
  A receipt that predates grouped tracking has no default ("state what is going back").
- **Validation/blockers:** draw must total the received quantity; not on hand → named shortfall; **layer consumed** → "Part of this delivery was already used from stock. Reverse is not possible —
  issue or return the remainder and recount" (`LAYER_CONSUMED`); QA disposition exists (existing refusal); reason mandatory.
- **API:** missing `GET /api/v1/goods-receipts/{id}/reversal-preview` (new read Action: per line default draw, current on-hand, coverable, consumed?) and `POST …/reverse` accepting
  `package_draws: {lineId: [...]}` (the Action parameter exists). **Permissions:** `purchasing.goods-receipt.reverse` + `inventory.package-state.reverse` + `.view`; location scope.
- **Mobile:** full-screen dialog, one line per step.

## Permissions, scope and gating summary

Every endpoint is guarded twice (request and Action) per [authorization.md](../architecture/authorization.md); each new route gets a grant for the demo personas and an entry in
`WriteRouteGuardTest`/`OpenReadRoutesTest`/`RecordScopeCoverageTest`. Reads follow stock scope (404 outside the person's units, 403 where a company-wide figure cannot be limited).
The UI hides a control when the capability is missing and shows the reason when it is the only blocker. No screen shows cost without `inventory.valuation.view`.

## Smallest first slice: "grouped lifecycle from one item card"

Goal: in the browser, take XYZ's emulsion from quantity-only to grouped, receive, issue and watch the breakdown — without building every screen.

1. **Read API + `PackageBreakdown` + the Item "Package tracking" card** (§Shared, §1 card only).
2. **Activation wizard** with opening count (§1) — three endpoints.
3. **Receipt composition panel** on the existing goods receipt form (§2) — one endpoint.
4. **Issue as a `RecordSheet` from the card** (§3), grouped items only, with `suggest` — two endpoints. No standalone menu screen yet.

That is one new read resource, two small action endpoints, two composition endpoints and four UI pieces; it exercises activation, the observed receipt, an issue with an opened can, and the
live breakdown, and it can be driven by one Playwright spec on the dev stack. **Next slices** reuse the editor and card: ② transfer sheet + count composition entry (they share the draw editor and
`PackageBreakdown`), ③ reversal dialog, ④ standalone Issue/Transfer menu screens and quantity-only forms once their capabilities are decided.

## Status of the first slice (built 2026-10-08)

| Piece | Where |
|---|---|
| `GET /package-balances`, `GET /items/{item}/package-tracking`, `POST …/package-tracking/preview` and `…/activate`, `POST /package-checkpoints`, `POST /package-draws/suggest`, `POST /stock-issues`, `PUT /goods-receipt-lines/{line}/package-composition` | `Inventory/Http/Controllers/Api/V1` (PackageStock requests), `Purchasing/Http/Controllers/Api/V1/GoodsReceiptLineCompositionController` |
| `reason_code` beside `message` on 422s | `DomainInvariantException::$reasonCode`, rendered in `bootstrap/app.php`; codes include `NEEDS_COUNT_FIRST`, `STALE_EVIDENCE`, `DRAW_NOT_ON_HAND`, `UNTRACKED_LEVEL`, `LAYER_CONSUMED`, `NEEDS_OBSERVED_COMPOSITION`, `SUPPLIER_QA_UNSUPPORTED`, `ACTIVATION_BLOCKED`, `PACKAGE_TRACKED_ITEM` |
| `PackageBreakdown`, `PackageDrawEditor`, `ItemPackageTrackingCard`, `ActivationWizard`, `ReceiptCompositionPanel`, `IssuePackageStockDialog` | `frontend/packages/core-ui/src/features/package-stock/` (card on Item detail; panel on the goods receipt detail; the wizard and issue are centred dialogs) |
| Acceptance test | `frontend/apps/e2e/tests/package-tracking-lifecycle.spec.ts` — quantity only → activate → receive observed packages → issue with an opened can → balance 4 cans + 2 L loose = 22 L |

Deviations from the design: the wizard and issue use `Dialog` (a task started from the item card) rather than `RecordSheet`; the receipt panel sits on the receipt **detail** screen of a draft (lines must exist to record against) and holds the Post button until each package-tracked line is recorded; `GET …/package-tracking` is also readable by people who create receipts, issue or count (it exposes only the package structure). **Transfer built 2026-10-08:** `POST /api/v1/stock-transfers` (`TransferGroupedStock` wraps `TransferPackagedStock`; capability `inventory.package-state.transfer` + `.view`, both locations in warehouse scope, idempotent on `request_key`, suggestions also available to transfer-only users), a "Transfer stock…" dialog on the item card on the shared draw plan (`use-package-draw-plan`, `draw-plan-section`) and a count dialog behind the "count destination first" blocker (`NEEDS_COUNT_FIRST`). The destination-blocker Playwright case mocks the destination balance as uncounted because a legitimately activated item cannot reach that state; the server refusal is covered by the API tests. **Count and reversal built 2026-10-08:** `POST /physical-count-lines/{line}/composition` (`RecordPhysicalCountComposition`, count capability) and `POST /physical-count-lines/{line}/refresh` (`RefreshPhysicalCountLine`, create capability); the line resource gains `grouped`, `composition` and `stale_reason` (never the book); the detail screen shows `GroupedCountedCell`/`CountCompositionDialog`, a verdict under the variance, `FoundCostField` (suggestion from the latest stock layer, shown with its date, never applied until chosen) and blocks posting while any line is stale. `GET /goods-receipts/{id}/reversal-preview` (`PreviewGoodsReceiptReversal` → `PreviewGroupedReceiptReversal`) returns per line the observed draw, what is on hand, the nearest coverable draw and blockers (`LAYER_CONSUMED`, `NEEDS_COUNT_FIRST`, `STALE_EVIDENCE`, `NEEDS_DECLARED_DRAW`, `NOT_POSTED`, `QA_DISPOSITION`); `POST …/reverse` accepts `package_draws`; the existing reverse section on the receipt detail embeds `ReceiptReversalPanel`. Not built: standalone Issue/Transfer menu screens.

## Open decisions

- A stock-issue/transfer capability for quantity-only items (none exists); proposed: do not build the quantity-only forms in the first slices.
- Stable `reason_code`s on domain refusals (small, additive change to `DomainInvariantException`/the 422 body).
- Whether found-stock cost may default from the last receipt for counters-with-post-rights (proposed yes, editable).

## Related

- [Delivery plan](package-balance-delivery.md), [handoff](package-balance-handoff.md), [grouped operations](../domain/package-issue-operations.md)
- [ADR 0090](../decisions/0090-package-aware-stock-issue-semantics.md), [0091](../decisions/0091-activate-grouped-package-tracking-and-fail-closed-enforcement.md),
  [0092](../decisions/0092-package-aware-physical-count-posting.md), [0093](../decisions/0093-package-aware-goods-receipt-reversal.md)
- [UI building blocks](../architecture/ui-building-blocks.md), [user interface](../architecture/user-interface.md)

## v1 sign-off (2026-10-08)

**Grouped package tracking v1 is complete.** Verified end to end in the browser against the dev stack (Playwright, each on a fresh item): activation → receipt composition → issue (`package-tracking-lifecycle`); transfer with the destination-needs-count block (`package-transfer`); count — composition-only, stale line refreshed and recounted, found stock with a confirmed cost, posted by a second person (`package-count`); receipt reversal — as received, changed form, delivery already used (`package-reversal`); supplier QA — held delivery, partial release by declared portion, rejection, impossible portion refused (`package-qa`); phone width for count, issue, transfer and reversal (`package-mobile`); and a quantity-only item untouched by all of it (`package-quantity-only`). Backend: the architecture ratchets, grouped issue/transfer/count/reversal/QA suites and the database-level conservation checks pass; capabilities roll out through migration `2026_10_08_140000`.

**v1 capabilities:** per-item opt-in activation from a reconciled count; physical-composition receipt (usable or, for inspection-required lines, held); issue and transfer by physical draw with server proposal; physical count by composition (composition-only versus governed variance, found cost); whole-receipt reversal of untouched deliveries with a declared draw; supplier QA release/rejection by declared portion; fail-closed database enforcement for every stock writer; capability rollout and role recipes; reason codes on every refusal.

**Known operational limitations** (accepted for v1, each with its refusal or workaround):
- Rejected stock stays on hand (shown apart, never usable) until a supplier-return/scrap movement exists; the same is true of quantity-only stock today.
- A scope holding held or rejected packages cannot be counted until QA has decided them.
- A sealed held can is released or rejected whole; opening or splitting held material needs a future governed operation. A dispositioned QA line cannot be reversed.
- Counts and activation refuse serial, SPECIFIC-valued and QA-dependent-layer stock; recalculation, cutover and negative-stock overrides are refused for grouped items.
- Issue and Transfer are reached from the item page, one location at a time; the transfer, issue and reversal dialogs are checked at phone width but not redesigned for it; role recipes are documented, not shipped as templates.

**Later enhancements, deliberately not v1:** supplier return/scrap movement for rejected stock, a governed change of tracked levels, deactivation/collapse back to quantity-only, container-level tracking, line-level or partial receipt reversal, a value-threshold approval for counts, QA/count reconciliation of held stock, standalone Issue/Transfer menu screens.
