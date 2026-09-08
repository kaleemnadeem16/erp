<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Uom;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\Status;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

final class InventoryDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $inventory = ChartOfAccount::query()->where('code', '1100')->firstOrFail();
        $revenue = ChartOfAccount::query()->where('code', '4000')->firstOrFail();
        $cogs = ChartOfAccount::query()->where('code', '5000')->firstOrFail();
        $rawExpense = ChartOfAccount::query()->where('code', '5100')->firstOrFail();

        $raw = $this->category('demo-raw', 'Raw materials', null, ValuationMethod::Avco, $revenue->getKey(), $rawExpense->getKey(), $inventory->getKey());
        $pigments = $this->category('demo-pigments', 'Pigments', $raw->getKey(), null, null, null, null);
        $solvents = $this->category('demo-solvents', 'Solvents', $raw->getKey(), ValuationMethod::Fifo, null, null, null);
        $finished = $this->category('demo-finished', 'Finished goods', null, ValuationMethod::Fifo, $revenue->getKey(), $cogs->getKey(), $inventory->getKey());
        $services = $this->category('demo-services', 'Services and resources', null, ValuationMethod::Avco, $revenue->getKey(), $cogs->getKey(), null);
        $equipment = $this->category('demo-equipment', 'Plant and equipment', null, ValuationMethod::Specific, $revenue->getKey(), $cogs->getKey(), $inventory->getKey());

        $kg = Uom::query()->where('code', 'kg')->firstOrFail();
        $litre = Uom::query()->where('code', 'l')->firstOrFail();
        $each = Uom::query()->where('code', 'ea')->firstOrFail();
        $hour = Uom::query()->where('code', 'hr')->firstOrFail();

        $items = [
            ['code' => 'demo-pig-blue', 'name' => 'Phthalo Blue Pigment', 'description' => 'High-strength blue pigment for industrial coatings.', 'type' => ItemType::Raw, 'uom' => $kg, 'stocked' => true, 'purchasable' => true, 'sellable' => false, 'method' => ValuationMethod::Avco, 'scope' => AvcoPoolScope::CompanyItem, 'tracking' => ItemTracking::None, 'category' => $pigments],
            ['code' => 'demo-pig-red', 'name' => 'Iron Oxide Red Pigment', 'description' => 'Batch-tracked red oxide pigment.', 'type' => ItemType::Raw, 'uom' => $kg, 'stocked' => true, 'purchasable' => true, 'sellable' => false, 'method' => ValuationMethod::Avco, 'scope' => AvcoPoolScope::CompanyItemBatch, 'tracking' => ItemTracking::Batch, 'category' => $pigments],
            ['code' => 'demo-thinner', 'name' => 'Industrial Thinner', 'description' => 'General-purpose solvent issued by litre.', 'type' => ItemType::Raw, 'uom' => $litre, 'stocked' => true, 'purchasable' => true, 'sellable' => true, 'method' => ValuationMethod::Fifo, 'scope' => null, 'tracking' => ItemTracking::None, 'category' => $solvents],
            ['code' => 'demo-paint-white', 'name' => 'White Enamel Paint 20L', 'description' => 'Finished batch-tracked enamel paint.', 'type' => ItemType::Finished, 'uom' => $each, 'stocked' => true, 'purchasable' => false, 'sellable' => true, 'method' => ValuationMethod::Fifo, 'scope' => null, 'tracking' => ItemTracking::Batch, 'category' => $finished],
            ['code' => 'demo-site-application', 'name' => 'On-site Paint Application', 'description' => 'Customer-facing application service.', 'type' => ItemType::Service, 'uom' => $hour, 'stocked' => false, 'purchasable' => false, 'sellable' => true, 'method' => ValuationMethod::Avco, 'scope' => AvcoPoolScope::CompanyItem, 'tracking' => ItemTracking::None, 'category' => $services],
            ['code' => 'demo-painter-hour', 'name' => 'Painter Labour Hour', 'description' => 'Direct labour resource used in formulations and jobs.', 'type' => ItemType::Labour, 'uom' => $hour, 'stocked' => false, 'purchasable' => false, 'sellable' => false, 'method' => ValuationMethod::Avco, 'scope' => AvcoPoolScope::CompanyItem, 'tracking' => ItemTracking::None, 'category' => $services],
            ['code' => 'demo-spray-unit', 'name' => 'Airless Spray Unit', 'description' => 'Serial-tracked plant resource.', 'type' => ItemType::Plant, 'uom' => $each, 'stocked' => true, 'purchasable' => true, 'sellable' => false, 'method' => ValuationMethod::Specific, 'scope' => null, 'tracking' => ItemTracking::Serial, 'category' => $equipment],
        ];

        foreach ($items as $data) {
            $item = Item::withTrashed()->updateOrCreate(
                ['code' => $data['code']],
                [
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'type' => $data['type'],
                    'stock_uom_id' => $data['uom']->getKey(),
                    'is_stocked' => $data['stocked'],
                    'is_purchasable' => $data['purchasable'],
                    'is_sellable' => $data['sellable'],
                    'valuation_method' => $data['method'],
                    'avco_pool_scope' => $data['scope'],
                    'tracking' => $data['tracking'],
                    'category_id' => $data['category']->getKey(),
                    'income_account_id' => null,
                    'expense_account_id' => null,
                    'inventory_account_id' => null,
                    'custom_fields' => [],
                    'status' => Status::Active,
                ],
            );
            $item->restore();
        }

        $this->warehouse('demo-main', 'Main Warehouse', 'stock', 'Main Stock');
        $this->warehouse('demo-khi', 'Karachi Depot', 'stock', 'Karachi Stock');
    }

    private function category(string $code, string $name, ?int $parentId, ?ValuationMethod $method, ?int $incomeId, ?int $expenseId, ?int $inventoryId): Category
    {
        return Category::query()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'parent_id' => $parentId,
                'default_valuation_method' => $method,
                'income_account_id' => $incomeId,
                'expense_account_id' => $expenseId,
                'inventory_account_id' => $inventoryId,
            ],
        );
    }

    private function warehouse(string $code, string $name, string $locationCode, string $locationName): void
    {
        $hasOtherDefault = Warehouse::query()
            ->where('is_default', true)
            ->where('code', '!=', $code)
            ->exists();

        $warehouse = Warehouse::query()->updateOrCreate(
            ['code' => $code],
            ['name' => $name, 'is_default' => $code === 'demo-main' && ! $hasOtherDefault, 'active' => true],
        );

        $warehouse->locations()->updateOrCreate(
            ['code' => $locationCode],
            ['name' => $locationName, 'active' => true],
        );
    }
}
