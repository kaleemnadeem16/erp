<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Actions\CancelPurchaseOrder;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Purchasing-module demo data: item/supplier relationships, then every
 * purchase order and goods receipt in the demo set. Kept in this one file
 * (not split further) because everything here shares the same small cast of
 * items/suppliers and reads naturally as one purchasing story; the
 * inventory-workflow seeders (physical counts, cutovers, overrides,
 * recalculation — see StockDemoSeeder) are a genuinely separate concern and
 * live apart for exactly that reason — one file per module, not one giant
 * fixture file, per AGENTS.md's "maintain every module's demo data
 * separately" guidance.
 *
 * Deliberately covers every purchase-order status (DRAFT, ISSUED, CLOSED via
 * full receipt, CANCELLED) and every goods-receipt status (DRAFT, POSTED,
 * REVERSED) at least once, plus one multi-line PO — so every screen state is
 * exercised by real seeded data, not just the statuses that happen to fall
 * out of the "receive everything immediately" happy path.
 */
final class PurchasingDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $kg = Uom::query()->where('code', 'kg')->firstOrFail();
        $litre = Uom::query()->where('code', 'l')->firstOrFail();
        $each = Uom::query()->where('code', 'ea')->firstOrFail();
        $roll = Uom::query()->where('code', 'roll')->firstOrFail();
        $box = Uom::query()->where('code', 'box')->firstOrFail();

        $relationships = [
            ['item' => 'demo-pig-blue', 'supplier' => 'sup-punjab', 'supplier_code' => 'PB-25KG', 'priority' => 1, 'lead' => 4, 'moq' => '25.0000', 'uom' => $kg],
            ['item' => 'demo-pig-blue', 'supplier' => 'sup-pakchem', 'supplier_code' => 'BLUE-PHT', 'priority' => 2, 'lead' => 7, 'moq' => '50.0000', 'uom' => $kg],
            ['item' => 'demo-pig-red', 'supplier' => 'sup-punjab', 'supplier_code' => 'IROX-RD', 'priority' => 1, 'lead' => 5, 'moq' => '25.0000', 'uom' => $kg],
            ['item' => 'demo-thinner', 'supplier' => 'sup-pakchem', 'supplier_code' => 'THN-200', 'priority' => 1, 'lead' => 6, 'moq' => '200.0000', 'uom' => $litre],
            ['item' => 'demo-spray-unit', 'supplier' => 'sup-coatings', 'supplier_code' => 'ASU-450', 'priority' => 1, 'lead' => 12, 'moq' => '1.0000', 'uom' => $each],
            // Sold by this supplier in a pack unit, which the item's pack conversion converts to its
            // stock unit (PackConversionsDemoSeeder). A purchase order line for one of these items at
            // this supplier therefore starts on Roll / Box rather than the stock unit.
            // Minimum orders are written in the purchase unit (10 rolls, 4 boxes); no consumer
            // defines or enforces the MOQ's unit yet, so this is a display convention only.
            ['item' => 'demo-masking-tape', 'supplier' => 'sup-coatings', 'supplier_code' => 'MSK-50M', 'priority' => 1, 'lead' => 3, 'moq' => '10.0000', 'uom' => $roll],
            ['item' => 'demo-sanding-disc', 'supplier' => 'sup-coatings', 'supplier_code' => 'SND-BX50', 'priority' => 1, 'lead' => 3, 'moq' => '4.0000', 'uom' => $box],
        ];

        foreach ($relationships as $data) {
            $item = Item::query()->where('code', $data['item'])->firstOrFail();
            $supplier = SupplierAccount::query()->where('code', $data['supplier'])->firstOrFail();

            ItemSupplier::query()->updateOrCreate(
                ['item_id' => $item->getKey(), 'supplier_account_id' => $supplier->getKey()],
                [
                    'supplier_item_code' => $data['supplier_code'],
                    'priority' => $data['priority'],
                    'lead_time_days' => $data['lead'],
                    'minimum_order_qty' => $data['moq'],
                    'purchase_uom_id' => $data['uom']->getKey(),
                    'active' => true,
                ],
            );
        }

        $demoUser = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'demo-main')->firstOrFail();
        $location = $warehouse->locations()->where('code', 'stock')->firstOrFail();
        $currency = Currency::query()->where('code', 'PKR')->firstOrFail();

        $this->seedGoodsReceipts($location, $currency);
        $this->seedDraftGoodsReceipt($location, $currency);
        $this->seedReversedGoodsReceipt($location, $currency, $demoUser);
        $this->seedInventoryOverviewDemoData($location, $currency, $demoUser);
        $this->seedMultiLinePurchaseOrder($currency);
        $this->seedDraftPurchaseOrder($currency);
        $this->seedCancelledPurchaseOrder($currency);
    }

    private function seedGoodsReceipts(Location $location, Currency $currency): void
    {
        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-punjab')->firstOrFail();

        // This item can already carry earlier stock movements in a long-lived dev
        // database (manually posted test moves, predating this seeder's own run) —
        // a receipt dated before the latest one is rejected as backdated. Anchor
        // relative to that latest move instead of a fixed "days ago" so this stays
        // safe to run against both a fresh database and an already-populated one.
        $latestExistingMove = StockMove::query()->where('item_id', $item->getKey())->max('moved_at');
        $earliestSafeDate = $latestExistingMove !== null
            ? Carbon::parse($latestExistingMove)->addDay()->min(now())
            : now()->subDays(20);

        foreach ([
            ['key' => 'demo-receipt-pigblue-1', 'qty' => '150.0000', 'cost' => '480.0000', 'movedAt' => $earliestSafeDate->copy()],
            ['key' => 'demo-receipt-pigblue-2', 'qty' => '80.0000', 'cost' => '495.0000', 'movedAt' => $earliestSafeDate->copy()->addDays(3)->min(now())],
        ] as $data) {
            if (GoodsReceipt::query()->where('idempotency_key', $data['key'])->exists()) {
                continue;
            }

            $orderedAt = $data['movedAt']->format('Y-m-d');
            $order = app(CreatePurchaseOrder::class)([
                'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
                'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                'order_date' => $orderedAt,
                'lines' => [[
                    'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                    'ordered_qty' => $data['qty'], 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => $data['cost'],
                ]],
            ]);
            $order = app(IssuePurchaseOrder::class)($order);
            $line = $order->lines->sole();

            $receipt = app(CreateGoodsReceipt::class)([
                'supplier_account_id' => $supplier->getKey(),
                'physical_arrival_at' => $data['movedAt']->toIso8601String(),
                'posting_date' => $orderedAt,
                'lines' => [[
                    'purchase_order_line_id' => $line->getKey(), 'item_id' => $item->getKey(), 'location_id' => $location->getKey(),
                    'received_qty' => $data['qty'], 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => $data['cost'],
                    'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                    'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                ]],
            ], $data['key']);
            app(PostGoodsReceipt::class)($receipt);
        }
    }

    /**
     * Left DRAFT on purpose, mirroring StockDemoSeeder's "leave one live step
     * for the demo login" pattern — so the Goods Receipts list shows a real
     * DRAFT row, not just POSTED ones, and the demo login can practice the
     * post step itself via the detail screen.
     */
    private function seedDraftGoodsReceipt(Location $location, Currency $currency): void
    {
        $key = 'demo-receipt-thinner-draft';
        if (GoodsReceipt::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        $item = Item::query()->where('code', 'demo-thinner')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-pakchem')->firstOrFail();
        $orderedAt = now()->subDays(2)->format('Y-m-d');

        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                'ordered_qty' => '25.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '345.0000',
            ]],
        ]);
        $order = app(IssuePurchaseOrder::class)($order);
        $line = $order->lines->sole();

        app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $supplier->getKey(),
            'physical_arrival_at' => now()->subDays(2)->toIso8601String(),
            'posting_date' => $orderedAt,
            'lines' => [[
                'purchase_order_line_id' => $line->getKey(), 'item_id' => $item->getKey(), 'location_id' => $location->getKey(),
                'received_qty' => '25.0000', 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => '345.0000',
                'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            ]],
        ], $key);
        // Deliberately never posted.
    }

    /**
     * Posted, then immediately reversed with a correction reason — so the
     * Goods Receipts list and detail screen both show a real REVERSED
     * example (reversal's own inventory-effect reversal, blocked-by-QA
     * copy, etc.) instead of that status only ever existing in tests.
     *
     * Uses its own dedicated item, not demo-paint-white — same reasoning as
     * seedRecalculationRequest's dedicated item: ReverseGoodsReceipt posts
     * its compensating movement dated `now()`, which is always later than
     * any other seeded receipt's backdated date. Reusing an item that gets
     * a *second*, later-seeded backdated receipt (as demo-paint-white does,
     * in seedInventoryOverviewDemoData below) would make that second receipt
     * collide with this one's reversal under
     * ReceiptPoster's "no receipt before a later movement" guard.
     */
    private function seedReversedGoodsReceipt(Location $location, Currency $currency, User $demoUser): void
    {
        $key = 'demo-receipt-paintgrey-reversed';
        if (GoodsReceipt::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        $category = Category::query()->where('code', 'demo-finished')->firstOrFail();
        $each = Uom::query()->where('code', 'ea')->firstOrFail();
        $item = Item::withTrashed()->updateOrCreate(
            ['code' => 'demo-paint-grey'],
            [
                'name' => 'Grey Primer Paint 20L', 'description' => 'Dedicated item for the goods-receipt reversal demo.',
                'type' => ItemType::Finished, 'stock_uom_id' => $each->id, 'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => true,
                'valuation_method' => ValuationMethod::Fifo, 'avco_pool_scope' => null, 'tracking' => ItemTracking::Batch,
                'category_id' => $category->id, 'inventory_account_id' => null, 'custom_fields' => [],
            ],
        );
        $item->restore();
        if ($item->resolveInventoryAccountId() === null) {
            $item->update(['inventory_account_id' => ChartOfAccount::query()->firstOrFail()->id]);
        }

        $supplier = SupplierAccount::query()->where('code', 'sup-coatings')->firstOrFail();
        $orderedAt = now()->subDays(6)->format('Y-m-d');

        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                'ordered_qty' => '10.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '1180.0000',
            ]],
        ]);
        $order = app(IssuePurchaseOrder::class)($order);
        $line = $order->lines->sole();

        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $supplier->getKey(),
            'physical_arrival_at' => now()->subDays(6)->toIso8601String(),
            'posting_date' => $orderedAt,
            'lines' => [[
                'purchase_order_line_id' => $line->getKey(), 'item_id' => $item->getKey(), 'location_id' => $location->getKey(),
                'received_qty' => '10.0000', 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => '1180.0000',
                'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                'batch_no' => 'PG-WRONGBATCH',
            ]],
        ], $key);
        $receipt = app(PostGoodsReceipt::class)($receipt);
        app(ReverseGoodsReceipt::class)($receipt, 'Demo: wrong batch received — reversed and re-received separately.', $demoUser->getKey());
    }

    /**
     * Beyond demo-pig-blue's two receipts above, gives every other stocked
     * demo item a real on-hand quantity, plus a QA-held split and an
     * outstanding (issued, unreceived) PO line — so the Inventory Overview
     * screen (on-hand/available/QA-held/on-order/shortage) has real, varied
     * numbers instead of a column of zeros. All through the same real
     * Actions as seedGoodsReceipts above, never raw inventory-table writes.
     * Shortage is deliberately left at 0 here: StockDemoSeeder's negative
     * stock overrides already leave two real override requests pending
     * decision, so the demo login can produce a real shortage itself by
     * approving one rather than one being pre-baked.
     */
    private function seedInventoryOverviewDemoData(Location $location, Currency $currency, User $demoUser): void
    {
        $this->seedPlainReceipt($location, $currency, 'demo-pig-red', 'sup-punjab', 'demo-receipt-pigred-1', '100.0000', '520.0000', 10, 'PR-OPEN-01');
        $this->seedPlainReceipt($location, $currency, 'demo-thinner', 'sup-pakchem', 'demo-receipt-thinner-1', '60.0000', '340.0000', 8, null);
        $this->seedPlainReceipt($location, $currency, 'demo-paint-white', 'sup-coatings', 'demo-receipt-paintwhite-1', '40.0000', '1200.0000', 5, 'PW-OPEN-01');
        $this->seedSerialisedReceipts($location, $currency);
        $this->seedQaHeldReceipt($location, $currency, $demoUser);
        $this->seedOutstandingPurchaseOrder($currency);
    }

    /** A single-line PO, issued and fully received in one go — the same shape as seedGoodsReceipts' loop, parametrised for reuse across the remaining items. */
    private function seedPlainReceipt(
        Location $location, Currency $currency, string $itemCode, string $supplierCode,
        string $idempotencyKey, string $qty, string $cost, int $daysAgo, ?string $batchNo
    ): void {
        if (GoodsReceipt::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $item = Item::query()->where('code', $itemCode)->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', $supplierCode)->firstOrFail();
        $orderedAt = now()->subDays($daysAgo)->format('Y-m-d');

        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                'ordered_qty' => $qty, 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => $cost,
            ]],
        ]);
        $order = app(IssuePurchaseOrder::class)($order);
        $line = $order->lines->sole();

        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $supplier->getKey(),
            'physical_arrival_at' => now()->subDays($daysAgo)->toIso8601String(),
            'posting_date' => $orderedAt,
            'lines' => [[
                'purchase_order_line_id' => $line->getKey(), 'item_id' => $item->getKey(), 'location_id' => $location->getKey(),
                'received_qty' => $qty, 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => $cost,
                'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                'batch_no' => $batchNo,
            ]],
        ], $idempotencyKey);
        app(PostGoodsReceipt::class)($receipt);
    }

    /** demo-spray-unit is SERIAL-tracked (and SPECIFIC-valued): each receipt line is exactly one unit with its own serial. */
    private function seedSerialisedReceipts(Location $location, Currency $currency): void
    {
        $item = Item::query()->where('code', 'demo-spray-unit')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-coatings')->firstOrFail();

        foreach ([
            ['key' => 'demo-receipt-sprayunit-1', 'serial' => 'SU-1001', 'daysAgo' => 12],
            ['key' => 'demo-receipt-sprayunit-2', 'serial' => 'SU-1002', 'daysAgo' => 9],
        ] as $data) {
            if (GoodsReceipt::query()->where('idempotency_key', $data['key'])->exists()) {
                continue;
            }

            $orderedAt = now()->subDays($data['daysAgo'])->format('Y-m-d');
            $order = app(CreatePurchaseOrder::class)([
                'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
                'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                'order_date' => $orderedAt,
                'lines' => [[
                    'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                    'ordered_qty' => '1.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '45000.0000',
                ]],
            ]);
            $order = app(IssuePurchaseOrder::class)($order);
            $line = $order->lines->sole();

            $receipt = app(CreateGoodsReceipt::class)([
                'supplier_account_id' => $supplier->getKey(),
                'physical_arrival_at' => now()->subDays($data['daysAgo'])->toIso8601String(),
                'posting_date' => $orderedAt,
                'lines' => [[
                    'purchase_order_line_id' => $line->getKey(), 'item_id' => $item->getKey(), 'location_id' => $location->getKey(),
                    'received_qty' => '1.0000', 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => '45000.0000',
                    'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                    'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                    'serial_no' => $data['serial'],
                ]],
            ], $data['key']);
            app(PostGoodsReceipt::class)($receipt);
        }
    }

    /**
     * A second, QA-required demo-pig-red receipt, left with a partial PASS
     * disposition — 30 of 50 released, 20 still QA-held — so the demo login
     * can resolve the remaining branch live via the Goods Receipt detail
     * screen instead of every QA case arriving pre-resolved.
     */
    private function seedQaHeldReceipt(Location $location, Currency $currency, User $demoUser): void
    {
        $key = 'demo-receipt-pigred-qa';
        if (GoodsReceipt::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        $item = Item::query()->where('code', 'demo-pig-red')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-punjab')->firstOrFail();
        $orderedAt = now()->subDays(4)->format('Y-m-d');

        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                'ordered_qty' => '50.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '540.0000',
                'qa_required' => true,
            ]],
        ]);
        $order = app(IssuePurchaseOrder::class)($order);
        $line = $order->lines->sole();

        $receipt = app(CreateGoodsReceipt::class)([
            'supplier_account_id' => $supplier->getKey(),
            'physical_arrival_at' => now()->subDays(4)->toIso8601String(),
            'posting_date' => $orderedAt,
            'lines' => [[
                'purchase_order_line_id' => $line->getKey(), 'item_id' => $item->getKey(), 'location_id' => $location->getKey(),
                'received_qty' => '50.0000', 'received_uom_id' => $item->stock_uom_id, 'provisional_unit_cost' => '540.0000',
                'currency_id' => $currency->getKey(), 'fx_rate' => '1.000000000',
                'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                'batch_no' => 'PR-QA-01',
            ]],
        ], $key);
        app(PostGoodsReceipt::class)($receipt);

        app(PostQualityDisposition::class)(
            $receipt->lines->sole(),
            ['disposition' => 'PASS', 'qty' => '30.0000', 'reason' => 'Demo: partial QA pass, remainder pending inspection.'],
            $demoUser->getKey(),
        );
    }

    /**
     * Deliberately left ISSUED and unreceived — an outstanding order the
     * demo login can receive live, and the source of demo-pig-blue's
     * on_order_qty on the Inventory Overview screen.
     */
    private function seedOutstandingPurchaseOrder(Currency $currency): void
    {
        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-pakchem')->firstOrFail();

        $alreadySeeded = PurchaseOrder::query()->where('supplier_account_id', $supplier->getKey())
            ->whereHas('lines', fn ($query) => $query->where('item_id', $item->getKey())->where('ordered_qty', '75.0000'))
            ->exists();
        if ($alreadySeeded) {
            return;
        }

        $orderedAt = now()->subDays(2)->format('Y-m-d');
        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name,
                'ordered_qty' => '75.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '505.0000',
            ]],
        ]);
        app(IssuePurchaseOrder::class)($order);
    }

    /**
     * The only multi-line PO in the demo set — every other one here is
     * single-line for brevity. Each line's `description` is deliberately
     * written distinct from its item's own `name` (a per-item, not
     * per-order, field) to prove the line description is a real independent
     * remark and not just an echo of the item name. `notes` carries an
     * order-level remark — a field no single line owns.
     */
    private function seedMultiLinePurchaseOrder(Currency $currency): void
    {
        $blue = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $red = Item::query()->where('code', 'demo-pig-red')->firstOrFail();
        $thinner = Item::query()->where('code', 'demo-thinner')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-punjab')->firstOrFail();

        $alreadySeeded = PurchaseOrder::query()->where('supplier_account_id', $supplier->getKey())
            ->whereHas('lines', fn ($query) => $query->where('item_id', $red->getKey())->where('ordered_qty', '30.0000'))
            ->exists();
        if ($alreadySeeded) {
            return;
        }

        $orderedAt = now()->subDays(1)->format('Y-m-d');
        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'notes' => 'Demo: quarterly bulk restock, three items from one supplier in one order. Deliver to the main gate, not the side dock.',
            'lines' => [
                [
                    'line_number' => 1, 'item_id' => $blue->getKey(), 'description' => 'Primary blue pigment — bulk restock',
                    'ordered_qty' => '60.0000', 'purchase_uom_id' => $blue->stock_uom_id, 'unit_price' => '505.0000',
                ],
                [
                    'line_number' => 2, 'item_id' => $red->getKey(), 'description' => 'Red pigment — top-up for the red-oxide line',
                    'ordered_qty' => '30.0000', 'purchase_uom_id' => $red->stock_uom_id, 'unit_price' => '540.0000',
                ],
                [
                    'line_number' => 3, 'item_id' => $thinner->getKey(), 'description' => 'Thinner — general workshop consumption',
                    'ordered_qty' => '40.0000', 'purchase_uom_id' => $thinner->stock_uom_id, 'unit_price' => '345.0000',
                ],
            ],
        ]);
        app(IssuePurchaseOrder::class)($order);
    }

    /** Never issued — every other demo PO is ISSUED or further along; this is the list's only DRAFT row. */
    private function seedDraftPurchaseOrder(Currency $currency): void
    {
        $item = Item::query()->where('code', 'demo-spray-unit')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-coatings')->firstOrFail();

        $alreadySeeded = PurchaseOrder::query()->where('supplier_account_id', $supplier->getKey())->where('status', 'DRAFT')
            ->whereHas('lines', fn ($query) => $query->where('item_id', $item->getKey()))
            ->exists();
        if ($alreadySeeded) {
            return;
        }

        $orderedAt = now()->format('Y-m-d');
        app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt, 'notes' => 'Demo: still being priced out, not ready to issue yet.',
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => 'Spray unit — pending quote confirmation',
                'ordered_qty' => '2.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '44500.0000',
            ]],
        ]);
        // Deliberately never issued.
    }

    /** Issued, then cancelled — the list's only CANCELLED row. */
    private function seedCancelledPurchaseOrder(Currency $currency): void
    {
        $item = Item::query()->where('code', 'demo-thinner')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-pakchem')->firstOrFail();

        $alreadySeeded = PurchaseOrder::query()->where('supplier_account_id', $supplier->getKey())->where('status', 'CANCELLED')
            ->whereHas('lines', fn ($query) => $query->where('item_id', $item->getKey()))
            ->exists();
        if ($alreadySeeded) {
            return;
        }

        $orderedAt = now()->subDays(15)->format('Y-m-d');
        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $orderedAt, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => $orderedAt,
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => 'Thinner — superseded by a larger combined order',
                'ordered_qty' => '15.0000', 'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => '345.0000',
            ]],
        ]);
        $order = app(IssuePurchaseOrder::class)($order);
        app(CancelPurchaseOrder::class)($order, 'Demo: superseded by the combined multi-item order placed the same week.');
    }
}
