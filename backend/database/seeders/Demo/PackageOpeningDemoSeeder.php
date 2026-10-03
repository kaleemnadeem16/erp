<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** A dedicated counted fixture for read-only open/unpack proposals. */
final class PackageOpeningDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo(['inventory.package-state.view', 'inventory.physical_count.create']);
        if (PackageOperation::query()->where('request_key', 'demo-package-opening-checkpoint')->exists()) {
            return;
        }
        DB::transaction(function () use ($actor): void {
            $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
            $item = Item::withTrashed()->where('code', 'demo-package-opening')->first()
                ?? app(CreateItem::class)(['code' => 'demo-package-opening', 'name' => 'Thinner — opening and unpacking preview',
                    'type' => 'RAW', 'stock_uom_id' => $base->stock_uom_id, 'category_id' => $base->category_id,
                    'valuation_method' => 'FIFO', 'tracking' => 'NONE', 'inventory_account_id' => $base->inventory_account_id,
                    'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false], $actor);
            $inner = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'bottle-100ml')->first()
                ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'bottle-100ml', 'name' => 'Sealed 100 mL bottle', 'stock_quantity' => '0.1'], $actor);
            $outer = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'carton-2l')->first()
                ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'carton-2l', 'name' => 'Carton of 20 sealed bottles',
                    'stock_quantity' => '2', 'inner_packaging_id' => $inner->id, 'inner_count' => 20], $actor);
            $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
            $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
            if (! StockLayer::query()->where('item_id', $item->id)->exists()) {
                foreach ([['1', '800', 991001], ['3', '900', 991002]] as [$qty, $cost, $source]) {
                    app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $location->id,
                        'qty' => $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
                        'source_type' => 'DIRECT_RECEIPT', 'source_id' => $source, 'unit_cost' => $cost,
                        'base_unit_cost' => $cost, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000']);
                }
            }
            app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $location->id,
                'request_key' => 'demo-package-opening-checkpoint', 'reason' => 'Fictional observed count: two sealed cartons. Opening/unpacking are read-only proposals; no tracking activation.',
                'expected_quantity' => '4', 'expected_layers' => StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $outer->id, 'count' => 2]]], $actor);
        });
    }
}
