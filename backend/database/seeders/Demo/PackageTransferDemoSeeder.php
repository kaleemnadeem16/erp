<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Actions\TransferPackagedStock;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * XYZ Paints (ADR 0090): the same counted 3 sealed 5 L cans + 5 L loose at the main depot (layers 6 L at 8.00 and 14 L at 10.00), then one
 * grouped transfer of 7 L to Karachi: a whole sealed can, which arrives sealed, and 2 L from a second can opened for the transfer, which
 * arrives as loose stock. The main depot is left with 1 can and 8 L loose; Karachi with 1 can and 2 L loose; FIFO cost 58.00. Created once
 * through the real Actions; a rerun never recomputes history or disturbs later manual stock progress. Tracking stays quantity-only.
 */
final class PackageTransferDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo(['inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.transfer', 'inventory.physical_count.create']);
        if (PackageOperation::query()->where('request_key', 'demo-xyz-transfer-7l')->exists()) {
            return; // Immutable history and later manual progress are preserved.
        }
        DB::transaction(function () use ($actor): void {
            $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
            $item = Item::withTrashed()->where('code', 'demo-package-transferred')->first()
                ?? app(CreateItem::class)(['code' => 'demo-package-transferred', 'name' => 'XYZ Paints emulsion — grouped package transfer',
                    'type' => 'RAW', 'stock_uom_id' => $base->stock_uom_id, 'category_id' => $base->category_id,
                    'valuation_method' => 'FIFO', 'tracking' => 'NONE', 'inventory_account_id' => $base->inventory_account_id,
                    'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false], $actor);
            $can = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'can-5l')->first()
                ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'can-5l', 'name' => 'Sealed 5 L can', 'stock_quantity' => '5'], $actor);
            $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
            $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
            if (! StockLayer::query()->where('item_id', $item->id)->exists()) {
                foreach ([['6', '8', 993101], ['14', '10', 993102]] as [$qty, $cost, $source]) {
                    app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty,
                        'uom_id' => $item->stock_uom_id, 'moved_at' => now(), 'source_type' => 'DIRECT_RECEIPT', 'source_id' => $source,
                        'unit_cost' => $cost, 'base_unit_cost' => $cost, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000']);
                }
            }
            $count = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $location->id,
                'request_key' => 'demo-xyz-xfer-count', 'reason' => 'Fictional opening count: three sealed 5 L cans and 5 L loose. Tracking stays quantity-only.',
                'expected_quantity' => '20', 'expected_layers' => StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '5']]], $actor);
            $khi = Warehouse::query()->where('code', 'demo-khi')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
            app(TransferPackagedStock::class)(['source_predecessor_id' => $count->id, 'destination_predecessor_id' => null, 'to_location_id' => $khi->id,
                'request_key' => 'demo-xyz-transfer-7l', 'reason' => 'Restock Karachi: one sealed can plus 2 L from a second can opened for the transfer.',
                'source_type' => 'DEMO_STOCK_TRANSFER', 'source_id' => 993103, 'moved_at' => now(),
                'draw' => [['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1],
                    ['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2']]], $actor);
        });
    }
}
