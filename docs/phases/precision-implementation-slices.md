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
