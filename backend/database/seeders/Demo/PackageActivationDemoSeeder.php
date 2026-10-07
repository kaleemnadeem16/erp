<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
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
 * XYZ Paints (ADR 0091): a new emulsion item is counted (3 sealed 5 L cans + 5 L loose), grouped package tracking is activated from that count,
 * and the first job issue then goes through the package-aware door: 7 L, leaving 2 cans and 3 L loose. From activation on, a scalar issue,
 * transfer, receipt, count posting, cutover or recalculation for this item is refused — in the application and again by the database.
 * Created once through the real Actions; a rerun never recomputes history or disturbs later manual progress.
 */
final class PackageActivationDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo(['inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.issue', 'inventory.package-state.activate', 'inventory.physical_count.create']);
        if (PackageOperation::query()->where('request_key', 'demo-xyz-activated-issue')->exists()) {
            return; // Immutable history and later manual progress are preserved.
        }
        DB::transaction(function () use ($actor): void {
            $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
            $item = Item::withTrashed()->where('code', 'demo-package-activated')->first()
                ?? app(CreateItem::class)(['code' => 'demo-package-activated', 'name' => 'XYZ Paints emulsion — grouped tracking activated',
                    'type' => 'RAW', 'stock_uom_id' => $base->stock_uom_id, 'category_id' => $base->category_id,
                    'valuation_method' => 'FIFO', 'tracking' => 'NONE', 'inventory_account_id' => $base->inventory_account_id,
                    'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false], $actor);
            $can = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'can-5l')->first()
                ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'can-5l', 'name' => 'Sealed 5 L can', 'stock_quantity' => '5'], $actor);
            $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
            $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
            if (! StockLayer::query()->where('item_id', $item->id)->exists()) {
                foreach ([['6', '8', 994101], ['14', '10', 994102]] as [$qty, $cost, $source]) {
                    app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty,
                        'uom_id' => $item->stock_uom_id, 'moved_at' => now(), 'source_type' => 'DIRECT_RECEIPT', 'source_id' => $source,
                        'unit_cost' => $cost, 'base_unit_cost' => $cost, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000']);
                }
            }
            $count = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $location->id,
                'request_key' => 'demo-xyz-activation-count', 'reason' => 'Fictional opening count: three sealed 5 L cans and 5 L loose, taken to start grouped tracking.',
                'expected_quantity' => '20', 'expected_layers' => StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $can->id, 'count' => 3], ['quantity' => '5']]], $actor);
            app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'demo-xyz-activation', 'reason' => 'XYZ Paints starts tracking emulsion by sealed can and loose litres.', 'checkpoints' => [$count->id]], $actor);
            // From here on only package-aware operations can change this item's stock; this issue takes 7 L: the loose 5 L plus 2 L from a can opened for the job.
            app(IssuePackagedStock::class)(['predecessor_id' => $count->id, 'request_key' => 'demo-xyz-activated-issue',
                'reason' => 'First job after activation: loose 5 L plus 2 L from one opened can.',
                'source_type' => 'DEMO_JOB_ISSUE', 'source_id' => 994103, 'moved_at' => now(),
                'draw' => [['kind' => 'LOOSE', 'quantity' => '5'], ['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $can->id, 'count' => 1, 'quantity' => '2']]], $actor);
        });
    }
}
