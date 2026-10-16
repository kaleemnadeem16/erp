[← Phase index](README.md)

# Precision architecture — implementation slices and handoff

**2026-10-15 · ACCEPTED architecture ([0141](../decisions/0141-layer-precision-as-invariants-versioned-policy-and-presentation.md)–[0144](../decisions/0144-version-sales-unit-price-precision-within-four-place-storage.md)); slices SPECIFIED, none IMPLEMENTED.**
No application change, migration, grant registration, seeder change or push is authorised by this document. Each slice needs its own owner authorisation. Contract: [precision.md](../domain/precision.md).

## Implementation order

```text
PX0 shared quantity contract ──► PX1 B1 Sales correction
                             ├─► PX2 Inventory increments and conversions
                             └─► PX3 Purchasing (audit → pin → prospective validation → legacy only if justified)
PX3a production audit (no code dependency) ──► gates PX3 enforcement
PX4 frontend formatter/input ──► PX7 Settings navigation and cards
PX5 currency reference sync (independent) ──► PX6 FR4 precision policy (also needs AU0/AU1, FR0 done)
```
Parallel from day one: PX0, PX3a, PX4a, PX5. Sequential: PX1/PX2/PX3 after PX0; PX6 after PX5 and AU registration; PX7 after PX4 and at least one module card.

## Slices

| Slice | Scope | Depends on | Owner area and files | Must not touch |
|---|---|---|---|---|
| **PX0 Shared quantity contract** | A small value (`scale`, `maximum`, optional `increment`, `context`) and a representability check returning the exact result or `QUANTITY_NOT_REPRESENTABLE`; unit tests only | 0142 | New `backend/app/Support/Quantity/*` and `tests/Unit/Support/Quantity*` | Any module |
| **PX1 B1 correction** | Source-specific quantity contract in the Sales kernel: direct and service Items = ledger scale 4 plus the Item increment (remainder zero); Assembly work = 6 (offered scale still open, see below); manual = 4. `PricingInput` keeps the raw quantity and the adapter validates before any cost read; no change for valid inputs | PX0 | `Sales/Support/Financial/Arithmetic.php`, `Sales/Support/Pricing/{PricingInput,PricingSourceAdapter,ResolvedSource,ResolveSalesPrice}.php`, the four `Sources/*` adapters, `tests/Unit|Feature/Modules/Sales` | `MoneyCast`, Inventory, Purchasing |
| **PX2 Inventory increments and conversions** | Validate increments (≤ 4 places, multiple of 0.0001, stock equivalent representable) and package content ≤ 4 places on **new and edited** Items, units and packages; stable refusal where the package kernel now raises `toScale(4)`; read-only "non-conforming" listing; no stored value rewritten | PX0, 0142 | `Inventory/Http/Requests/{Item,ItemQuantityUnit}/*`, `Inventory/Actions/{MaintainItemPackaging,ItemUomConversionService}.php`, the package `Actions`/`Support` that call `toScale(4)`, item and pack-conversion screens, `InventoryDemoSeeder`, `PackConversionsDemoSeeder`, `PackagedPurchasingDemoSeeder` | Purchasing arithmetic |
| **PX3a Production audit** | Execute [the audit plan](precision-production-audit-plan.md) with the named operator; record the aggregate result | Owner go-ahead and named operator | Docs result record only | Production data |
| **PX3 Purchasing** | (1) pin the applicable Item-level increment on the issued order line so the over-receipt floor reads the pinned value (**needs its own migration authorisation**); (2) refuse unrepresentable conversions on **new and edited** order and receipt lines (validation only, same results for representable inputs); (3) legacy completion path only if PX3a justifies it | PX0, PX3a result, tests | `Purchasing/Actions/{BuildPurchaseOrderLineAttributes,DraftGoodsReceiptLines,PostGoodsReceipt}.php`, `PurchaseOrderLine` model and resource, migration, `PurchasingDemoSeeder` | Purchasing monetary arithmetic (0120) |
| **PX4 Frontend number foundation** | **PX4a** `formatDecimal` and `DecimalInput` (string-safe, scale and increment from the server, blocks and never rounds, marked when digits are hidden), unit-tested. **PX4b** adopt on Purchase Orders, Goods Receipts, Item Suppliers with today's output as default. **PX4c** counts, inventory overview, package stock. **PX4d** a ratchet against new `Number(…).toLocaleString` on decimal strings | PX4a none; PX4b–c after screen owners agree | New `frontend/packages/core-ui/src/lib/decimal*`, `components/ui/decimal-input.tsx`; then the listed feature folders | `core-api` generated files (regenerated only) |
| **PX5 Currency reference sync** | ISO 4217 dataset in the repository; an idempotent dry-run-by-default command run at deploy; used-currency detection from foreign-key metadata; audited; production path separate from demo | 0143 | New console command and dataset under `backend/`, `CurrencyServiceProvider` only if registering; `FoundationDemoSeeder` calls the same dataset | Runtime currency editing; production demo seeding |
| **PX6 Sales FR4 precision policy** | Company-wide unit-price entry precision 2–4 (default 4) and settlement rule inside the FR4 policy version; revalidate drafts, never rewrite | FR0 (done), AU0/AU1 registering `sales.financial.configure`, PX5 | Sales module (FR4 slice files) | Purchasing |
| **PX7 Settings navigation and cards** | Central Settings index; a read-only "Numbers and rounding" card per module (fixed values as text, real controls only where a policy exists); company display defaults reuse `company.profile.manage`, personal preference needs no duty | PX4, then per module card | `frontend/packages/core-ui/src/features/settings/*`, `apps/main/src/router.tsx` and `presentation.ts` (**shared hotspots**), `Company/Support/CompanyFields.php` if defaults are stored there | New settings tables |

### Per-slice demo data, tests and browser verification

| Slice | Seeder work (idempotent, real Actions, no fabricated events) | Tests | Browser check |
|---|---|---|---|
| PX1 | None | Sales unit and feature tests: increment, 4/6 scale, representability, cost unchanged | — |
| PX2 | Keep whole-piece, 0.001 L and kg items; every seeded increment/content must satisfy the contract; refused cases live in tests, not data | Request and Action tests; non-conforming list; re-run adds nothing | Item and pack-conversion forms refuse a finer increment |
| PX3 | A representable carton-to-stock order; a foreign-currency order once exchange rates exist | Open-line, pinned-floor, edited-line and receipt tests; audit command zero on demo | PO and receipt forms show the refusal reason |
| PX4 | None | Formatter table tests incl. `999999999999999.9999`; input blocks digits; ratchet | Existing PO/GR specs unchanged output; litre 1.275 shown exactly |
| PX5 | `FoundationDemoSeeder` uses the dataset; add JPY (0), KWD (3) and a 4-place example; **seed exchange rates (none exist today)** through the guarded rate Action | Sync dry run/apply, used-currency refusal, idempotent rerun | — |
| PX6 | Two policy versions and an issued quotation that keeps the first — only when real quotation issue exists | Version, revalidation, history tests | Settings card and draft revalidation banner |
| PX7 | Company display defaults via the Company field registry (`FoundationDemoSeeder` currently overwrites company settings: reconcile) | Navigation, read-only rendering, permission | Light/dark/phone, each module card |

Seeders refuse to run outside `local`/`testing`, register in `DemoDatabaseSeeder` in dependency order, and each ships a `…DemoSeederTest` proving the rerun adds nothing ([environments.md](../architecture/environments.md#development-demo-data)). `demo:seed` is never run against production (0129); production reference data uses PX5.

## Shared hotspots and ownership rules
`bootstrap/providers.php`, `apps/main/src/router.tsx`, `presentation.ts`, the generated `core-api` client, migration timestamps, `DemoDatabaseSeeder` ordering, `docs/decisions/README.md` (next number **0145**) and `docs/STATUS.md`. One agent at a time per hotspot; stage explicit paths; no stash, reset or push.

## Dependencies on other work
FR1 is **not** blocked by this work except that it must treat `currencies.decimal_places` as read-only authority. FR4 carries the policy; R9 (AU0/AU1) registers `sales.financial.configure` before PX6 or its card go live; Documents DF1 pins print precision per template version; no new duty is created and no grant is made.

## PX0 delivery record and integration guidance (2026-10-15)

**IMPLEMENTED locally, targeted tests only.** `App\Support\Quantity\{QuantityContract, QuantityContext, QuantitySign, ConvertedQuantity, QuantityRefusal}`: pure, database-free, no consumers yet.

| Need | Call | Result |
|---|---|---|
| Stock quantity | `QuantityContract::stockLedger($increment = null)->represent($v, QuantitySign::Positive)` | Value at four places, or a refusal |
| Document line | `QuantityContract::commercialLine($scale, $increment)` | The destination column's scale (0–9) |
| Assembly measure | `QuantityContract::assemblyTechnical()` | Six places |
| Factor evidence | `QuantityContract::factor($v)` | Positive, ≤ 9 places, ≤ 10 integer digits |
| Convert | `$destination->convert($qty, $factor)` | `ConvertedQuantity` (exact = result) or `QUANTITY_NOT_REPRESENTABLE` |
| Increment definition | `$destination->acceptIncrement($v)`, `acceptTransactionIncrement($v, $factor)` | Increment at destination scale, or `INCREMENT_*` |
| Detection | `$destination->isRepresentable($v)` | Boolean, never throws |

Reason codes: `QUANTITY_INVALID`, `_NEGATIVE`, `_ZERO`, `_OVERFLOW`, `_NOT_REPRESENTABLE`, `_INCREMENT_VIOLATED`; `INCREMENT_INVALID`, `_NOT_REPRESENTABLE`, `_INCOMPATIBLE`; `FACTOR_INVALID`, `_NOT_REPRESENTABLE`; `CONTRACT_INVALID`. All are `DomainInvariantException` (422). Floats, null, arrays and exponent forms are refused as invalid.

**Hand-off to the Sales (PX1) owner.** Replace `Arithmetic::quantity` with a per-source contract: direct and service Items `stockLedger($item->quantity_precision)`, Assembly work `assemblyTechnical()` (offered scale still open), manual `commercialLine(4)`; validate before any cost read. Also found while testing: `Sales/Support/Financial/CurrencyQuantum::unit()` uses `BigDecimal::one()->dividedBy(10^n)` with no scale, which throws `RoundingNecessaryException` for one or more places (untested, unused); use `BigDecimal::ofUnscaledValue(1, $places)`.

## Notes handed to PX5 (from the Phase 4 FR1 owner, 2026-10-15)
The exchange-rate master accepts zero and negative rates (`ManageExchangeRate`, `StoreExchangeRateRequest`/`UpdateExchangeRateRequest`, `exchange_rates.rate` has no CHECK). FR1 (`Sales/Support/Fx`) refuses a non-positive rate with `FX_RATE_INVALID` and does not fall back to an older rate. PX5 should review write-boundary positivity (a CHECK and the request/Action rules) in the Currency-owned slice, and the development database has no seeded rates, so PX5's seeding is a dependency of any FR1 demo.

## PX2 delivery record — Inventory (2026-10-15)

**IMPLEMENTED locally; targeted and wide backend tests only (see the commit report).** Development-stage Inventory is corrected directly (owner clarification): no legacy or grandfather path, and the production audit does not gate PX2.

| Area | Change |
|---|---|
| Item increment | `ItemQuantityRules::assertItem` in the `Item` saving hook: positive and reachable by the destination (stock ledger for a stocked item, a four-place commercial context otherwise). Reads the **raw** attribute, because the `decimal:9` cast would round a finer value to zero before the rule saw it |
| Increment change | Refused (`INCREMENT_INCOMPATIBLE`) if it would strand an active package's content or a pack unit's increment |
| Pack-unit conversions | `ItemQuantityUnit` hook: factor stays nine-place evidence (`FACTOR_*`); a unit increment is checked through its factor (`acceptTransactionIncrement`) |
| Package definitions | `ItemPackaging` creating hook: content exact at four places and a multiple of the item increment; a package increment checked through its content. The duplicate check in `MaintainItemPackaging` was removed |
| Posting | `ReceiptPoster`, `IssuePoster`, `TransferPoster` and `RecordPhysicalCountLine` read quantities through `StockQuantity::signed`; a quantity of more than four places is refused instead of being rounded by PostgreSQL on insert |
| Unhandled arithmetic | The three sites that multiplied a nine-place package content by a count and called `toScale(4)` (`RecordPhysicalCountComposition`, `PackageDrawPlanner`, `PostGroupedCountLine`) now use `StockQuantity::sealed/packageContent`; the seven private "exact quantity" helpers delegate to the contract and carry reason codes |
| Database | Migration `2026_10_15_100000_enforce_stock_quantity_representability`: CHECKs on `item_packagings` (content and increment) and on a **stocked** item's increment. Apply → rollback → re-apply verified on an isolated database |

**Reason codes returned (422 with `reason_code`):** `INCREMENT_INVALID`, `INCREMENT_NOT_REPRESENTABLE`, `INCREMENT_INCOMPATIBLE`, `FACTOR_INVALID`, `FACTOR_NOT_REPRESENTABLE`, `QUANTITY_NOT_REPRESENTABLE`, `QUANTITY_INCREMENT_VIOLATED`, `QUANTITY_ZERO`, `QUANTITY_NEGATIVE`, `QUANTITY_OVERFLOW`, `QUANTITY_INVALID`.

**Remaining Inventory paths (not changed, with reason):** item increments are **not** enforced on stock postings or counts (only on definitions and PO/receipt quantities elsewhere); `RecordReceiptComposition` is Purchasing's file (PX3); cutover opening quantities and recalculation take stored values; the many `toScale(4)` calls that only format an already-stored four-place value are intentionally left.

**Frontend hand-off (not done; another agent owns these screens).**
1. `packages/core-ui/src/features/items/item-form.tsx` lines 54–60: the client rule allows up to nine decimals for `quantity_precision`; change to at most four places (hint "up to 4 decimal places") for stocked items.
2. `features/item-quantity-units/pack-conversion-common.tsx` line 67: the precision rule has the same nine-place pattern; the factor keeps nine places.
3. Show the server's `message` for 422 responses carrying the codes above next to the field (the item, pack-conversion and package forms already display field errors; confirm each renders `errors.quantity_precision`/`stock_quantity`).
4. Demo journeys: no change needed; a Playwright check that a finer increment is refused is optional.

**PX3 dependencies.** PX3 can reuse `QuantityContract::convert` for purchase-to-stock conversion and `StockQuantity` for receipt quantities. It still needs: the pinned order-line increment (its own migration), a pass over `BuildPurchaseOrderLineAttributes`, `DraftGoodsReceiptLines`, `PostGoodsReceipt` (live Item increment in the tolerance floor) and `RecordReceiptComposition`, and the Purchasing demo data.

## PX3 delivery record — Purchasing (2026-10-16)

**IMPLEMENTED locally; targeted tests plus a wide backend run (see the commit report).** Development-stage Purchasing is corrected directly; no grandfather or legacy-completion path was built, and the production audit does not gate it. Purchasing **monetary** arithmetic (four-place half-up, base conversions, priced quantity, line amount) is unchanged (0120).

| Area | Change |
|---|---|
| Order line | `BuildPurchaseOrderLineAttributes`: the order quantity is validated at its own four-place column; the agreed package/unit increment is checked on the order quantity; the stock quantity is `ItemQuantityRules::withIncrement($item)->convert(qty, factor)` — exact at the ledger and on the Item increment, or refused (`QUANTITY_NOT_REPRESENTABLE` / `QUANTITY_INCREMENT_VIOLATED`, field `ordered_qty`). 7 cartons × 6.666666667 is refused instead of stored as 46.6667 |
| Receipt draft | `DraftGoodsReceiptLines`: received quantity checked at its column and against the agreed package increment; stock quantity converted exactly (field `received_qty`). The Item increment is **not** applied to what physically arrives |
| Receipt composition | `RecordReceiptComposition` observed quantities go through `StockQuantity::positive` (reason codes) |
| Pinned increment | New `purchase_order_lines.stock_increment decimal(19,9) NULL`, written by `IssuePurchaseOrder` with the other issue snapshots; issuing refuses (`QUANTITY_INCREMENT_VIOLATED`) a line whose saved stock quantity no longer fits the Item increment now in force. The existing snapshot fields were not sufficient: `package_increment` is in the order unit and covers only package/unit increments, and `offer_snapshot` belongs to the supplier offer |
| Over-receipt ceiling | `PostGoodsReceipt` floors the tolerance ceiling to the **pinned** `stock_increment`, not the Item's live setting. A later Item edit cannot reinterpret an issued order |
| Migration | `2026_10_16_100000_pin_stock_increment_on_purchase_order_lines`: additive column; orders already issued get the Item's increment of that day as a **labelled baseline** (no earlier value exists), with the user triggers switched off for that one statement. Apply → rollback → re-apply verified on an isolated database |
| PX2 follow-up | A unit or package increment must itself be at most four places in its own unit (`ItemQuantityRules`), because it is typed into a four-place quantity column |
| Test that changed | `test_specific_receipt_with_a_fractional_uom_factor…` encoded the silent rounding of 3 × 0.333333333 to 1.0000; it now uses an exact factor (4 × 0.25) and a new test asserts the refusal |

**Distinction recorded (no new rule introduced).** Entry increments (Item, unit, package) are enforced on order quantities and definitions; the converted stock quantity must be representable at the ledger on every path; physical postings, counts and receipts are **not** forced onto the Item increment, because a count or a delivery legitimately records what is there. The package increment agreed on a PO line still binds its receipts. No accepted contract asks for more.

**Ambiguity to confirm (not decided by code).** An Item with an increment of 1 receiving 2.5 against an order is accepted at receipt (physical fact) even though it could not have been ordered. Recommendation: keep it, and treat a non-multiple receipt as a QA/operational matter, not a posting refusal.

**Frontend hand-off (not done; another agent owns shared UI).** `purchase-orders/purchase-order-form.tsx` (line 93, `ordered_qty: decimal("Quantity", true)`) and `purchase-order-line-fields.tsx` / `purchase-line-package-fields.tsx`: show the server message for `ordered_qty` refusals (`QUANTITY_NOT_REPRESENTABLE`, `QUANTITY_INCREMENT_VIOLATED`, `FACTOR_*`); `goods-receipts/goods-receipt-form.tsx` and `goods-receipts-list-screen.tsx`: the same for `received_qty`. No API shape changed (the pinned increment is not exposed), so the generated client is unchanged.

**Demo data.** Not changed: the Purchasing, Packaged-purchasing and Pack-conversion seeders already use exact conversions, and their seeder tests (including idempotent reruns) pass.

**Remaining:** PX4 (frontend decimal foundation), PX5 (currency reference data, including positivity of exchange rates), PX6/PX7 as scheduled; RecordReceiptPackageEvidence/quality-disposition paths were not changed.

## Genuinely unresolved technical decisions
1. Where the shared quantity contract lives (proposed `app/Support/Quantity`).
2. A read-only precision catalogue endpoint versus additive scale fields on existing resources.
3. Order-line pinning: a new column on `purchase_order_lines` versus the existing snapshot JSON, and the truthful baseline for lines already issued.
4. Storage of the legacy completion evidence (only if PX3a justifies it).
5. Where company display defaults are stored (Company field registry preferred).
6. The currency command name, output and audit sink.
7. Whether FR4's precision/settlement part may ship before FR3 (tax), since it does not need tax.
8. The offered Assembly quotation quantity scale — reconcile with R2/R5 first; not assumed to be six.
9. Whether a later non-stocked/technical quantity context gets its own ceiling.

## Related
- [precision.md](../domain/precision.md) · [audit plan](precision-production-audit-plan.md) · [0141](../decisions/0141-layer-precision-as-invariants-versioned-policy-and-presentation.md) · [0142](../decisions/0142-represent-stock-quantities-exactly-and-supersede-0050-increment-validation.md) · [0143](../decisions/0143-maintain-currency-minor-units-as-governed-reference-data.md) · [0144](../decisions/0144-version-sales-unit-price-precision-within-four-place-storage.md)
- [Phase 4 implementation handoff](phase-4-implementation-handoff.md) · [R4 slices](phase-4-r4-implementation-slices.md) · [Pre-push checks](../architecture/pre-push-checks.md)
