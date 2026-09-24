<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\Uom;
use App\Modules\Estimating\Models\ResourceRate;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\Status;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * The LABOUR/PLANT estimating-rate source — see docs/domain/assemblies.md
 * "Resource rates". `demo-painter-hour` (InventoryDemoSeeder) already
 * exists as a LABOUR item; this seeder adds the one PLANT item it needs
 * itself rather than reach into InventoryDemoSeeder for something that
 * isn't there — same rule FormulationsDemoSeeder already follows for
 * demo-formulation-binder.
 *
 * `demo-spray-unit` (InventoryDemoSeeder) is deliberately not reused here —
 * it models owned, serial-tracked equipment (a capital asset), a different
 * concept from an hourly-rated PLANT *resource*, even though both currently
 * share the PLANT item type. See docs/domain/items.md "Resource rates" for
 * that distinction.
 */
final class ResourceRatesDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $hour = Uom::query()->where('code', 'hr')->firstOrFail();
        $services = Category::query()->where('code', 'demo-services')->firstOrFail();

        $scaffold = Item::withTrashed()->updateOrCreate(
            ['code' => 'demo-plant-scaffold'],
            [
                'name' => 'Scaffold Hire', 'description' => 'Hourly-rated plant resource used in assembly costing.',
                'type' => ItemType::Plant, 'stock_uom_id' => $hour->id, 'is_stocked' => false,
                'is_purchasable' => false, 'is_sellable' => false, 'valuation_method' => ValuationMethod::Avco,
                'category_id' => $services->id, 'custom_fields' => [], 'status' => Status::Active,
            ],
        );
        $scaffold->restore();

        $painter = Item::query()->where('code', 'demo-painter-hour')->firstOrFail();

        $this->ensureRate($painter, '18.0000', $hour, '2026-01-01');
        $this->ensureRate($scaffold, '12.0000', $hour, '2026-01-01');
    }

    private function ensureRate(Item $item, string $rate, Uom $rateUom, string $effectiveFrom): void
    {
        ResourceRate::query()->firstOrCreate(
            ['item_id' => $item->id, 'effective_from' => $effectiveFrom],
            ['rate' => $rate, 'rate_uom_id' => $rateUom->id],
        );
    }
}
