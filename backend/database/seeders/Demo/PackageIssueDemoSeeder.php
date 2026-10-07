<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\IssuePackagedStock;
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

/**
 * XYZ Paints (ADR 0090): litres, a 5 L can, FIFO. Layer A holds 6 L at 8.00 and layer B 14 L at 10.00. A counted checkpoint records
 * 3 sealed cans and 5 L loose; one grouped issue then takes 12 L — the loose 5 L, one whole can, and 2 L from a second can opened for
 * the purpose — leaving 1 can and 3 L loose, costed 108.00 by the ordinary allocator. Created once, through the real Actions; a rerun
 * never recomputes history or disturbs later manual stock progress. Tracking stays quantity-only.
 */
final class PackageIssueDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo(['inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.issue', 'inventory.physical_count.create']);
        if (PackageOperation::query()->where('request_key', 'demo-xyz-issue-12l')->exists()) {
            return; // Immutable history and later manual progress are preserved.
        }
        DB::transaction(function () use ($actor): void {
            $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
            $item = Item::withTrashed()->where('code', 'demo-package-issued')->first()
                ?? app(CreateItem::class)(['code' => 'demo-package-issued', 'name' => 'XYZ Paints emulsion — grouped package issue',
                    'type' => 'RAW', 'stock_uom_id' => $base->stock_uom_id, 'category_id' => $base->category_id,
                    'valuation_method' => 'FIFO', 'tracking' => 'NONE', 'inventory_account_id' => $base->inventory_account_id,
                    'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false], $actor);
            $can = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'can-5l')->first()
                ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'can-5l', 'name' => 'Sealed 5 L can', 'stock_quantity' => '5'], $actor);
            $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
            $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
            if (! StockLayer::query()->where('item_id', $item->id)->exists()) {
                foreach ([['6', '8', 992101], ['14', '10', 992102]] as [$qty, $cost, $source]) {
                    app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty,
                        'uom_id' => $item->stock_uom_id, 'moved_at' => now(), 'source_type' => 'DIRECT_RECEIPT', 'source_id' => $source,
                        'unit_cost' => $cost, 'base_unit_cost' => $cost, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000']);
                }
            }
            $count = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $location->id,
                'request_key' => 'demo-xyz-count', 'reason' => 'Fictional opening count: three sealed 5 L cans and 5 L loose. Tracking stays quantity-only.',
                'expected_quantity' => '20', 'expected_layers' => StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '5']]], $actor);
            app(IssuePackagedStock::class)(['predecessor_id' => $count->id, 'request_key' => 'demo-xyz-issue-12l',
                'reason' => 'Job 1042: take the loose 5 L, one whole can and 2 L from a second can opened for the job.',
                'source_type' => 'DEMO_JOB_ISSUE', 'source_id' => 992103, 'moved_at' => now(),
                'draw' => [['kind' => 'LOOSE', 'quantity' => '5'], ['kind' => 'SEALED', 'packaging_id' => $can->id, 'count' => 1],
                    ['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2']]], $actor);
        });
    }
}
