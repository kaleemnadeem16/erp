<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Actions\CreateGoodsReceipt;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostGoodsReceipt;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * XYZ Paints (ADR 0094): grouped tracking is activated from a count of 3 sealed 5 L cans (15 L). An inspection-required delivery of 2 cans + 2 L loose (12 L)
 * arrives and is held. QA then passes 7 L — one sealed can and the 2 L loose — which become usable; the other can stays held awaiting inspection. Created once
 * through the real Actions; a rerun never recomputes history or disturbs later manual progress.
 */
final class PackageQaDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo(['inventory.package-state.view', 'inventory.package-state.activate', 'inventory.physical_count.create', 'purchasing.quality-disposition.post']);
        if (PackageOperation::query()->where('request_key', 'demo-xyz-qa-count')->exists()) {
            return; // Immutable history and later manual progress are preserved.
        }
        DB::transaction(function () use ($actor): void {
            $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
            $unit = Uom::query()->where('code', 'l')->firstOrFail();
            $item = Item::withTrashed()->where('code', 'demo-package-qa')->first()
                ?? app(CreateItem::class)(['code' => 'demo-package-qa', 'name' => 'XYZ Paints emulsion — grouped supplier QA',
                    'type' => 'RAW', 'stock_uom_id' => $unit->id, 'category_id' => $base->category_id, 'valuation_method' => 'FIFO', 'tracking' => 'NONE',
                    'inventory_account_id' => $base->inventory_account_id, 'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false], $actor);
            $can = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'can-5l')->first()
                ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'can-5l', 'name' => 'Sealed 5 L can', 'stock_quantity' => '5'], $actor);
            $supplier = SupplierAccount::query()->where('code', 'sup-pakchem')->firstOrFail();
            $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
            $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
            app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $location->id, 'qty' => '15', 'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
                'source_type' => 'DIRECT_RECEIPT', 'source_id' => 996201, 'unit_cost' => '9', 'base_unit_cost' => '9', 'currency_id' => $currency->id, 'fx_rate' => '1.000000000']);
            $count = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $location->id, 'request_key' => 'demo-xyz-qa-count',
                'reason' => 'Fictional opening count: three sealed 5 L cans.', 'expected_quantity' => '15',
                'expected_layers' => StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $can->id, 'count' => 3]]], $actor);
            app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'demo-xyz-qa-activation', 'reason' => 'Track emulsion by sealed can.', 'checkpoints' => [$count->id]], $actor);

            $order = app(CreatePurchaseOrder::class)([
                'supplier_account_id' => $supplier->id, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000', 'fx_rate_date' => now()->toDateString(),
                'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => now()->toDateString(),
                'notes' => '[Package QA demo] An inspection-required delivery of 5 L cans, partly released.',
                'lines' => [['line_number' => 1, 'item_id' => $item->id, 'description' => $item->name, 'ordered_qty' => '12', 'purchase_uom_id' => $unit->id,
                    'unit_price' => '10', 'price_uom_id' => $unit->id, 'price_quantity' => '1', 'qa_required' => true]],
            ], $actor);
            app(IssuePurchaseOrder::class)($order, $actor);
            $receipt = app(CreateGoodsReceipt::class)([
                'supplier_account_id' => $supplier->id, 'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->toDateString(),
                'lines' => [['purchase_order_line_id' => $order->lines()->sole()->id, 'item_id' => $item->id, 'location_id' => $location->id, 'received_qty' => '12', 'received_uom_id' => $unit->id]],
            ], $actor, 'demo-xyz-qa-receipt');
            app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['packaging_id' => $can->id, 'count' => 2], ['quantity' => '2']], 'Two sealed cans and 2 L loose, held for inspection.', $actor);
            app(PostGoodsReceipt::class)($receipt, $actor);
            // QA passes one sealed can and the loose litres; the other can stays held awaiting inspection.
            app(PostQualityDisposition::class)($receipt->lines()->sole(), ['disposition' => 'PASS', 'qty' => '7', 'reason' => 'Inspected: viscosity and colour within tolerance.',
                'package_portion' => [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1], ['kind' => 'LOOSE', 'quantity' => '2']]], $actor->id, 'demo-xyz-qa-pass');
        });
    }
}
