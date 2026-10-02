<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\StockMixPolicy;
use App\Modules\Estimating\Support\MaterialSelectionPolicyStatus;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionSource;
use App\Modules\Estimating\Support\ResolvedStockMixControl;
use App\Modules\Estimating\Support\StockMixLevel;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;

/**
 * Which hand-chosen-stock control applies to each item (ADR 0076): the item's own setting, else the nearest category
 * ancestor's, else the company's, else the built-in INFORMATION. A stored row with no level means "no preference here" and
 * is passed over. The walk covers all three scopes already, although only the company scope can be set today.
 */
final class ResolveStockMixControls
{
    /**
     * @param  list<int>  $itemIds
     * @return array<int, ResolvedStockMixControl> keyed by item id
     */
    public function __invoke(array $itemIds): array
    {
        $itemIds = array_values(array_unique($itemIds));
        if ($itemIds === []) {
            return [];
        }

        $active = StockMixPolicy::query()->where('status', MaterialSelectionPolicyStatus::Active)->whereNotNull('level')->get();
        $byItem = $active->where('scope_type', MaterialSelectionScope::Item)->keyBy('scope_id');
        $byCategory = $active->where('scope_type', MaterialSelectionScope::ItemCategory)->keyBy('scope_id');
        $company = $active->firstWhere('scope_type', MaterialSelectionScope::Company);

        $categoryIds = Item::query()->whereIn('id', $itemIds)->pluck('category_id', 'id');
        $parents = $byCategory->isEmpty() ? collect() : Category::query()->pluck('parent_id', 'id');

        $out = [];
        foreach ($itemIds as $itemId) {
            $row = $byItem->get($itemId);
            $source = MaterialSelectionSource::Item;
            if ($row === null) {
                $source = MaterialSelectionSource::ItemCategory;
                $categoryId = $categoryIds->get($itemId);
                $seen = [];
                while ($categoryId !== null && ! isset($seen[$categoryId])) {
                    $seen[$categoryId] = true;
                    $row = $byCategory->get($categoryId);
                    if ($row !== null) {
                        break;
                    }
                    $categoryId = $parents->get($categoryId);
                }
            }
            if ($row === null && $company !== null) {
                $row = $company;
                $source = MaterialSelectionSource::Company;
            }
            $out[$itemId] = $row === null || $row->level === null
                ? new ResolvedStockMixControl(StockMixLevel::builtIn(), null, MaterialSelectionSource::BuiltIn, null, null)
                : new ResolvedStockMixControl($row->level, $row->threshold_percent, $source, $row->id, $row->scope_id);
        }

        return $out;
    }
}
