<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Concerns;

use App\Models\Currency;
use App\Models\Uom;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use Carbon\CarbonInterface;

/**
 * Shared by the stock-workflow demo seeders that each give every scenario its
 * own item: an exact (item, location, batch) can carry only one open
 * shortage, a recalculation targets one specific historical movement, and a
 * transfer's pools belong to one item — so scenarios that shared an item
 * would tangle each other's history, and nothing already seeded (or driven by
 * the e2e specs) should change because a scenario needed stock.
 *
 * The item is mutable master data, reconciled by code on every run; the
 * receipts it posts are immutable, so callers must guard them with their own
 * natural key and never call this on a rerun that already has the scenario.
 */
trait SeedsDedicatedStockItems
{
    private function dedicatedStockItem(
        string $code,
        string $name,
        string $description,
        string $categoryCode = 'demo-raw',
        ValuationMethod $method = ValuationMethod::Avco,
        ?AvcoPoolScope $scope = AvcoPoolScope::CompanyItem,
        bool $purchasable = false,
        string $uomCode = 'kg',
        ItemTracking $tracking = ItemTracking::None,
    ): Item {
        $category = Category::query()->where('code', $categoryCode)->firstOrFail();
        $item = Item::withTrashed()->updateOrCreate(
            ['code' => $code],
            [
                'name' => $name, 'description' => $description, 'type' => ItemType::Raw,
                'stock_uom_id' => Uom::query()->where('code', $uomCode)->firstOrFail()->id, 'is_stocked' => true,
                'is_purchasable' => $purchasable, 'is_sellable' => false,
                'valuation_method' => $method, 'avco_pool_scope' => $method === ValuationMethod::Avco ? $scope : null,
                'tracking' => $tracking, 'category_id' => $category->id, 'inventory_account_id' => null, 'custom_fields' => [],
            ],
        );
        $item->restore();
        if ($item->resolveInventoryAccountId() === null) {
            $item->update(['inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id]);
        }

        return $item;
    }

    private function postReceipt(
        Item $item,
        Location $location,
        Currency $currency,
        string $qty,
        string $cost,
        string $sourceType,
        int $sourceId,
        ?CarbonInterface $movedAt = null,
        ?string $serialNo = null,
    ): void {
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $item->stock_uom_id,
            'moved_at' => $movedAt ?? now(), 'source_type' => $sourceType, 'source_id' => $sourceId,
            'unit_cost' => $cost, 'currency_id' => $currency->id, 'fx_rate' => '1.000000000', 'base_unit_cost' => $cost,
            ...($serialNo === null ? [] : ['serial_no' => $serialNo]),
        ]);
    }
}
