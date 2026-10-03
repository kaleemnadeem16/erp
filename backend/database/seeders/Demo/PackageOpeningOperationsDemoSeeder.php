<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PostPackageOpening;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Create-once saved history, separate from the still-actionable preview fixture. */
final class PackageOpeningOperationsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo(['inventory.package-state.view', 'inventory.package-state.open', 'inventory.physical_count.create']);
        if (PackageOperation::query()->where('request_key', 'demo-saved-opening-count')->exists()) {
            return; // Preserve all history and subsequent manual progress; never infer new current stock.
        }
        DB::transaction(function () use ($actor): void {
            $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
            $item = Item::withTrashed()->where('code', 'demo-package-opened')->first()
                ?? app(CreateItem::class)(['code' => 'demo-package-opened', 'name' => 'Thinner — saved unpacking and opening history',
                    'type' => 'RAW', 'stock_uom_id' => $base->stock_uom_id, 'category_id' => $base->category_id,
                    'valuation_method' => 'FIFO', 'tracking' => 'NONE', 'inventory_account_id' => $base->inventory_account_id,
                    'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false], $actor);
            // This entire named scenario is created atomically; existing manual definitions are preserved.
            $inner = $this->pack($item->id, 'bottle-100ml', ['name' => 'Sealed 100 mL bottle', 'stock_quantity' => '0.1'], $actor);
            $outer = $this->pack($item->id, 'carton-2l', ['name' => 'Carton of 20 sealed bottles', 'stock_quantity' => '2',
                'inner_packaging_id' => $inner->id, 'inner_count' => 20], $actor);
            $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
            $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
            if (! StockLayer::query()->where('item_id', $item->id)->exists()) {
                foreach ([['1', '800', 991101], ['3', '900', 991102]] as [$qty, $cost, $source]) {
                    app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $location->id,
                        'qty' => $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
                        'source_type' => 'DIRECT_RECEIPT', 'source_id' => $source, 'unit_cost' => $cost,
                        'base_unit_cost' => $cost, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000']);
                }
            }
            $count = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $location->id,
                'request_key' => 'demo-saved-opening-count', 'reason' => 'Fictional observed count: two sealed cartons before unpack/open history. Tracking stays quantity-only.',
                'expected_quantity' => '4', 'expected_layers' => StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $outer->id, 'count' => 2]]], $actor);
            $parent = app(PostPackageOpening::class)(['operation_id' => $count->id,
                'state_id' => PackageState::query()->where('operation_id', $count->id)->sole()->id,
                'count' => 1, 'kind' => 'UNPACK', 'request_key' => 'demo-saved-unpack',
                'reason' => 'Unpack one carton: one carton plus twenty sealed bottles, still 4 L.'], $actor);
            foreach ([1, 2] as $step) {
                $bottles = PackageState::query()->where('operation_id', $parent->id)->where('item_packaging_id', $inner->id)->sole();
                $parent = app(PostPackageOpening::class)(['operation_id' => $parent->id, 'state_id' => $bottles->id,
                    'count' => 1, 'kind' => 'OPEN', 'request_key' => 'demo-saved-open-'.$step,
                    'reason' => 'Open one 100 mL bottle; append to combined leftovers, preserve stock and value.'], $actor);
            }
        });
    }

    /** @param array<string,mixed> $attributes */
    private function pack(int $itemId, string $code, array $attributes, User $actor): ItemPackaging
    {
        return ItemPackaging::query()->where('item_id', $itemId)->where('code', $code)->first()
            ?? app(MaintainItemPackaging::class)(array_merge($attributes, ['item_id' => $itemId, 'code' => $code]), $actor);
    }
}
