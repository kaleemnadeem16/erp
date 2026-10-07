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
use App\Modules\Purchasing\Actions\RecordReceiptComposition;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * XYZ Paints (ADR 0093): grouped tracking is activated from a count of 3 sealed 5 L cans. A delivery of 2 cans (10 L) arrives and is posted with its
 * observed composition, then turns out to be the wrong product and is reversed: the same 2 sealed cans go back, the layer reverses at its original cost,
 * and the opening stock is exactly where it was. A second delivery of 1 can stays posted. Created once through the real Actions; a rerun never
 * recomputes history or disturbs later manual progress.
 */
final class PackageReceiptReversalDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo(['inventory.package-state.view', 'inventory.package-state.activate', 'inventory.package-state.reverse', 'inventory.physical_count.create',
            'purchasing.goods-receipt.reverse']);
        if (PackageOperation::query()->where('request_key', 'demo-xyz-reversal-count')->exists()) {
            return; // Immutable history and later manual progress are preserved.
        }
        DB::transaction(function () use ($actor): void {
            $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
            $unit = Uom::query()->where('code', 'l')->firstOrFail();
            $item = Item::withTrashed()->where('code', 'demo-package-reversed')->first()
                ?? app(CreateItem::class)(['code' => 'demo-package-reversed', 'name' => 'XYZ Paints emulsion — grouped receipt reversal',
                    'type' => 'RAW', 'stock_uom_id' => $unit->id, 'category_id' => $base->category_id, 'valuation_method' => 'FIFO', 'tracking' => 'NONE',
                    'inventory_account_id' => $base->inventory_account_id, 'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false], $actor);
            $can = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'can-5l')->first()
                ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'can-5l', 'name' => 'Sealed 5 L can', 'stock_quantity' => '5'], $actor);
            $supplier = SupplierAccount::query()->where('code', 'sup-pakchem')->firstOrFail();
            $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
            $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
            app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $location->id, 'qty' => '15', 'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
                'source_type' => 'DIRECT_RECEIPT', 'source_id' => 996101, 'unit_cost' => '9', 'base_unit_cost' => '9', 'currency_id' => $currency->id, 'fx_rate' => '1.000000000']);
            $count = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $location->id, 'request_key' => 'demo-xyz-reversal-count',
                'reason' => 'Fictional opening count: three sealed 5 L cans.', 'expected_quantity' => '15',
                'expected_layers' => StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $can->id, 'count' => 3]]], $actor);
            app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'demo-xyz-reversal-activation', 'reason' => 'Track emulsion by sealed can.', 'checkpoints' => [$count->id]], $actor);

            $order = app(CreatePurchaseOrder::class)([
                'supplier_account_id' => $supplier->id, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000', 'fx_rate_date' => now()->toDateString(),
                'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => now()->toDateString(),
                'notes' => '[Package reversal demo] Two deliveries of 5 L cans; the first is reversed.',
                'lines' => [['line_number' => 1, 'item_id' => $item->id, 'description' => $item->name, 'ordered_qty' => '15', 'purchase_uom_id' => $unit->id,
                    'unit_price' => '10', 'price_uom_id' => $unit->id, 'price_quantity' => '1']],
            ], $actor);
            app(IssuePurchaseOrder::class)($order, $actor);
            $line = $order->lines()->sole();
            $receipts = [];
            foreach ([['wrong', '10', 2, 'Two sealed cans, the wrong product.'], ['kept', '5', 1, 'One sealed can, kept.']] as [$key, $qty, $cans, $note]) {
                $receipt = app(CreateGoodsReceipt::class)([
                    'supplier_account_id' => $supplier->id, 'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->toDateString(),
                    'lines' => [['purchase_order_line_id' => $line->id, 'item_id' => $item->id, 'location_id' => $location->id, 'received_qty' => $qty, 'received_uom_id' => $unit->id]],
                ], $actor, 'demo-xyz-reversal-'.$key);
                app(RecordReceiptComposition::class)($receipt->lines()->sole(), [['packaging_id' => $can->id, 'count' => $cans]], $note, $actor);
                app(PostGoodsReceipt::class)($receipt, $actor);
                $receipts[$key] = $receipt;
            }
            // The wrong delivery goes back: the draw defaults to the composition that was observed arriving, because those cans are still sealed and present.
            app(ReverseGoodsReceipt::class)($receipts['wrong'], 'Wrong product delivered; both sealed cans returned to the supplier.', $actor->id);
        });
    }
}
