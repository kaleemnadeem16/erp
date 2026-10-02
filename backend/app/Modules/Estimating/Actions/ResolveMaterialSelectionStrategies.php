<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\MaterialSelectionPolicy;
use App\Modules\Estimating\Support\MaterialSelectionPolicyStatus;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionSource;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Estimating\Support\ResolvedSelectionStrategy;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;

/**
 * Which selection strategy applies to each item (ADR 0053 §2): the item's own
 * setting, else the nearest item-category ancestor's, else the company's, else
 * the built-in MINIMISE_WASTE. A stored row with no strategy means "no
 * preference here" and is passed over. Reads only the rows in force now, so a
 * later change never reaches back into anything already produced.
 */
final class ResolveMaterialSelectionStrategies
{
    /** The company-level answer — what an item with no setting of its own or its category's falls back to. */
    public function companyLevel(?MaterialSelectionStrategy $override = null): ResolvedSelectionStrategy
    {
        if ($override !== null) {
            return new ResolvedSelectionStrategy($override, MaterialSelectionSource::RequestOverride, null, null);
        }
        $company = MaterialSelectionPolicy::query()
            ->where('status', MaterialSelectionPolicyStatus::Active)
            ->where('scope_type', MaterialSelectionScope::Company)
            ->whereNotNull('strategy')
            ->first();

        return $company === null
            ? new ResolvedSelectionStrategy(MaterialSelectionStrategy::companyDefault(), MaterialSelectionSource::BuiltIn, null, null)
            : new ResolvedSelectionStrategy($company->strategy ?? MaterialSelectionStrategy::companyDefault(), MaterialSelectionSource::Company, $company->id, null);
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, ResolvedSelectionStrategy> keyed by item id
     */
    public function __invoke(array $itemIds, ?MaterialSelectionStrategy $override = null): array
    {
        $itemIds = array_values(array_unique($itemIds));
        if ($itemIds === []) {
            return [];
        }

        $active = MaterialSelectionPolicy::query()
            ->where('status', MaterialSelectionPolicyStatus::Active)
            ->whereNotNull('strategy')
            ->get();
        $byItem = $active->where('scope_type', MaterialSelectionScope::Item)->keyBy('scope_id');
        $byCategory = $active->where('scope_type', MaterialSelectionScope::ItemCategory)->keyBy('scope_id');
        $company = $active->firstWhere('scope_type', MaterialSelectionScope::Company);

        $categoryIds = Item::query()->whereIn('id', $itemIds)->pluck('category_id', 'id');
        $parents = $byCategory->isEmpty() ? collect() : Category::query()->pluck('parent_id', 'id');

        $out = [];
        foreach ($itemIds as $itemId) {
            if ($override !== null) {
                $out[$itemId] = new ResolvedSelectionStrategy($override, MaterialSelectionSource::RequestOverride, null, null);

                continue;
            }
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
            $out[$itemId] = $row === null
                ? new ResolvedSelectionStrategy(MaterialSelectionStrategy::companyDefault(), MaterialSelectionSource::BuiltIn, null, null)
                : new ResolvedSelectionStrategy($row->strategy ?? MaterialSelectionStrategy::companyDefault(), $source, $row->id, $row->scope_id);
        }

        return $out;
    }
}
