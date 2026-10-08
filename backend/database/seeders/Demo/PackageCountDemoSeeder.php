<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\AddPhysicalCountLine;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\MaintainItemPackaging;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\PostPhysicalCount;
use App\Modules\Inventory\Actions\RecordPackageCheckpoint;
use App\Modules\Inventory\Actions\RecordPhysicalCountComposition;
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
 * XYZ Paints (ADR 0092): grouped tracking is activated from a count of 2 sealed 5 L cans + 2 L loose (12 L; layers 6 L at 8.00 and 6 L at 10.00).
 * Count 1 finds the ledger right but the shelf different (1 can + 7 L loose): no stock movement, only the composition is replaced. Count 2 finds
 * 1 L missing (1 can + 6 L): the governed issue of 1 L posts at the allocator's cost and the counted composition becomes authoritative. Created once
 * through the real Actions; a rerun never recomputes history or disturbs later manual progress.
 */
final class PackageCountDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $actor->givePermissionTo(['inventory.package-state.view', 'inventory.package-state.open', 'inventory.package-state.activate', 'inventory.physical_count.create', 'inventory.physical_count.count']);
        $this->seedReviewer();
        if (PackageOperation::query()->where('request_key', 'demo-xyz-count-opening')->exists()) {
            return; // Immutable history and later manual progress are preserved.
        }
        DB::transaction(function () use ($actor): void {
            $base = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
            $item = Item::withTrashed()->where('code', 'demo-package-counted')->first()
                ?? app(CreateItem::class)(['code' => 'demo-package-counted', 'name' => 'XYZ Paints emulsion — grouped physical count',
                    'type' => 'RAW', 'stock_uom_id' => $base->stock_uom_id, 'category_id' => $base->category_id,
                    'valuation_method' => 'FIFO', 'tracking' => 'NONE', 'inventory_account_id' => $base->inventory_account_id,
                    'is_stocked' => true, 'is_purchasable' => true, 'is_sellable' => false], $actor);
            $can = ItemPackaging::query()->where('item_id', $item->id)->where('code', 'can-5l')->first()
                ?? app(MaintainItemPackaging::class)(['item_id' => $item->id, 'code' => 'can-5l', 'name' => 'Sealed 5 L can', 'stock_quantity' => '5'], $actor);
            $location = Warehouse::query()->where('code', 'demo-main')->firstOrFail()->locations()->where('code', 'stock')->firstOrFail();
            $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
            if (! StockLayer::query()->where('item_id', $item->id)->exists()) {
                foreach ([['6', '8', 995101], ['6', '10', 995102]] as [$qty, $cost, $source]) {
                    app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty,
                        'uom_id' => $item->stock_uom_id, 'moved_at' => now(), 'source_type' => 'DIRECT_RECEIPT', 'source_id' => $source,
                        'unit_cost' => $cost, 'base_unit_cost' => $cost, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000']);
                }
            }
            $count = app(RecordPackageCheckpoint::class)(['item_id' => $item->id, 'location_id' => $location->id,
                'request_key' => 'demo-xyz-count-opening', 'reason' => 'Fictional opening count: three sealed 5 L cans and 5 L loose, taken to start grouped tracking.',
                'expected_quantity' => '12', 'expected_layers' => StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)->pluck('qty_remaining', 'id')->all(),
                'composition' => [['packaging_id' => $can->id, 'count' => 2], ['quantity' => '2']]], $actor);
            app(ActivateGroupedPackageTracking::class)(['item_id' => $item->id, 'request_key' => 'demo-xyz-count-activation', 'reason' => 'XYZ Paints starts tracking emulsion by sealed can and loose litres.', 'checkpoints' => [$count->id]], $actor);
            $supervisor = User::query()->where('email', 'demo-supervisor@erp.x1techs.com')->first() ?? $actor;
            $supervisor->givePermissionTo(['inventory.physical_count.post', 'inventory.package-state.recount', 'inventory.package-state.view']);
            $warehouseId = $location->warehouse_id;
            // Count 1: the ledger is right (12 L) but the shelf holds 1 can + 7 L loose, not 2 cans + 2 L. No stock movement; only the composition is replaced.
            $first = app(CreatePhysicalCount::class)(['warehouse_id' => $warehouseId], $actor);
            $line = app(AddPhysicalCountLine::class)($first, ['item_id' => $item->id, 'location_id' => $location->id], $actor);
            app(RecordPhysicalCountComposition::class)($line, [['packaging_id' => $can->id, 'count' => 1], ['quantity' => '7']], $actor);
            app(PostPhysicalCount::class)($first, [], $supervisor);
            // Count 2: 1 L is missing (1 can + 6 L loose found). The governed issue of 1 L posts, and the counted composition becomes the new state.
            $second = app(CreatePhysicalCount::class)(['warehouse_id' => $warehouseId], $actor);
            $line = app(AddPhysicalCountLine::class)($second, ['item_id' => $item->id, 'location_id' => $location->id], $actor);
            app(RecordPhysicalCountComposition::class)($line, [['packaging_id' => $can->id, 'count' => 1], ['quantity' => '6']], $actor);
            app(PostPhysicalCount::class)($second, [], $supervisor);
        });
    }

    /**
     * A second person who may post counts, replace a package composition and see costs, and who signs in with the demo password: the demo user creates
     * and counts, and nobody posts their own count (the browser tests sign this person in to post).
     */
    private function seedReviewer(): void
    {
        // The warehouse assistant handles grouped stock day to day: sees the breakdown, issues, opens and transfers (the role recipe in docs/domain/package-issue-operations.md).
        User::query()->where('email', 'demo-ops@erp.x1techs.com')->first()?->givePermissionTo(['inventory.package-state.view', 'inventory.package-state.issue', 'inventory.package-state.open', 'inventory.package-state.transfer']);
        $reviewer = User::query()->updateOrCreate(['email' => 'demo-count-reviewer@erp.x1techs.com'], ['name' => 'Demo Stock Reviewer', 'password' => config('demo.user.password')]);
        $reviewer->forceFill(['email_verified_at' => now()])->save();
        $reviewer->syncPermissions(['inventory.physical_count.post', 'inventory.package-state.recount', 'inventory.package-state.view', 'inventory.stock.view', 'inventory.valuation.view', 'inventory.movement.view']);
    }
}
