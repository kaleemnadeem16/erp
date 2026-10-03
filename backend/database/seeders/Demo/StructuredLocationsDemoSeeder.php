<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\UpdateInventorySettings;
use App\Modules\Inventory\Actions\UpdateLocation;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Modules\Inventory\Support\LocationType;
use App\Modules\Inventory\Support\WarehouseLocationRole;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Database\Seeders\Demo\Concerns\SeedsDedicatedStockItems;
use Illuminate\Database\Seeder;

/**
 * The STRUCTURED side of location handling (ADR 0041), which no other demo data touches: every
 * warehouse elsewhere is a single plain "stock" location. This switches the demo company to STRUCTURED
 * (a governed, audited policy change; it cannot be undone once locations carry structure) and gives the
 * Main Warehouse a realistic floor:
 *
 *   - a hierarchy: "Zone A" (a VIEW grouping that cannot hold stock) over two storage bins, with barcodes;
 *   - one location of each operational type — receiving dock, quarantine cage, returns bay, dispatch
 *     staging, scrap, production WIP, transit — each assigned its warehouse role;
 *   - a company mark on two locations (ADR 0067): the Returns bay is marked as COUNTING because returned
 *     stock is inspected on arrival and goes back to use, and the Overflow shelf is marked as NOT counting
 *     because it holds damaged or opened stock awaiting a decision;
 *   - phthalo blue pigment (the item the wall-paint assembly needs) in five of those places, so the
 *     Material Preview shows the effect: the dock counts by its type, the Returns bay by the company's
 *     mark, and the quarantine cage and Overflow shelf do not.
 *
 * Locations are reconciled by code each run. The pigment receipts are immutable stock moves, so each is
 * recognised by its natural key and never posted twice.
 */
final class StructuredLocationsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;
    use SeedsDedicatedStockItems;

    private const RECEIPT = 'DEMO_LOCATION_STOCK';

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $this->ensureStructured($actor);

        $main = Warehouse::query()->where('code', 'demo-main')->firstOrFail();
        $locations = $this->seedLocations($main);
        $this->seedRoles($main, $locations);
        $this->seedMarks($locations, $actor);
        $this->seedPigment($locations);
    }

    private function ensureStructured(User $actor): void
    {
        if (Company::query()->findOrFail(1)->inventory_location_mode === InventoryLocationMode::Structured) {
            return;
        }

        app(UpdateInventorySettings::class)(
            ['inventory_location_mode' => InventoryLocationMode::Structured->value],
            $actor,
            'Demo: the Main Warehouse is organised into zones, bins and operational locations.',
        );
    }

    /** @return array<string, Location> keyed by code */
    private function seedLocations(Warehouse $main): array
    {
        $stock = $main->locations()->where('code', 'stock')->firstOrFail();
        $zone = $this->location($main, 'zone-a', 'Zone A', LocationType::View, null, ['allows_stock' => false, 'sort_order' => 10]);

        $rows = [
            ['bin-a1', 'Bin A1', LocationType::Storage, $zone, ['barcode' => 'MAIN-A1', 'sort_order' => 11]],
            ['bin-a2', 'Bin A2', LocationType::Storage, $zone, ['barcode' => 'MAIN-A2', 'sort_order' => 12]],
            ['overflow', 'Overflow shelf', LocationType::Storage, $stock, ['sort_order' => 20]],
            ['recv', 'Receiving dock', LocationType::Receiving, null, ['sort_order' => 30]],
            ['quar', 'Quarantine cage', LocationType::Quarantine, null, ['sort_order' => 40]],
            ['returns', 'Returns bay', LocationType::Returns, null, ['sort_order' => 50]],
            ['dispatch', 'Dispatch staging', LocationType::Dispatch, null, ['sort_order' => 60]],
            ['scrap', 'Scrap bin', LocationType::Scrap, null, ['sort_order' => 70]],
            ['wip', 'Production floor (WIP)', LocationType::Production, null, ['sort_order' => 80]],
            ['transit', 'In transit', LocationType::Transit, null, ['sort_order' => 90]],
        ];

        $locations = ['stock' => $stock, 'zone-a' => $zone];
        foreach ($rows as [$code, $name, $type, $parent, $extra]) {
            $locations[$code] = $this->location($main, $code, $name, $type, $parent, $extra);
        }

        return $locations;
    }

    /** @param array<string, mixed> $extra */
    private function location(Warehouse $warehouse, string $code, string $name, LocationType $type, ?Location $parent, array $extra = []): Location
    {
        return Location::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'code' => $code],
            ['name' => $name, 'type' => $type, 'parent_id' => $parent?->id, 'active' => true] + $extra,
        );
    }

    /** @param array<string, Location> $locations */
    private function seedRoles(Warehouse $main, array $locations): void
    {
        $roles = [
            WarehouseLocationRole::Receiving->value => 'recv', WarehouseLocationRole::Quarantine->value => 'quar',
            WarehouseLocationRole::Dispatch->value => 'dispatch', WarehouseLocationRole::Returns->value => 'returns',
            WarehouseLocationRole::Transit->value => 'transit', WarehouseLocationRole::Production->value => 'wip',
        ];
        foreach ($roles as $role => $code) {
            WarehouseLocationAssignment::query()->updateOrCreate(
                ['warehouse_id' => $main->id, 'role' => $role],
                ['location_id' => $locations[$code]->id],
            );
        }
    }

    /** @param array<string, Location> $locations */
    private function seedMarks(array $locations, User $actor): void
    {
        $update = app(UpdateLocation::class);
        $update($locations['returns'], [
            'counts_as_usable' => true,
            'counts_as_usable_reason' => 'Returned stock is inspected on arrival and goes straight back into use.',
        ], $actor->id);
        $update($locations['overflow'], [
            'counts_as_usable' => false,
            'counts_as_usable_reason' => 'The overflow shelf holds damaged or opened stock awaiting a decision.',
        ], $actor->id);
    }

    /** @param array<string, Location> $locations */
    private function seedPigment(array $locations): void
    {
        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
        // Received at the pool's own average so the item's company-wide AVCO cost does not move.
        $pool = AvcoPool::query()->where('item_id', $item->id)->where('status', 'ACTIVE')->first();
        $cost = number_format((float) ($pool->base_avg_unit_cost ?? 100), 4, '.', '');

        $receipts = [1 => ['recv', '2.0000'], 2 => ['returns', '6.0000'], 3 => ['overflow', '4.0000'], 4 => ['quar', '3.0000'], 5 => ['bin-a1', '5.0000']];
        foreach ($receipts as $sourceId => [$code, $qty]) {
            if (StockMove::query()->where('source_type', self::RECEIPT)->where('source_id', $sourceId)->exists()) {
                continue;
            }
            $this->postReceipt($item, $locations[$code], $currency, $qty, $cost, self::RECEIPT, $sourceId);
        }
    }
}
