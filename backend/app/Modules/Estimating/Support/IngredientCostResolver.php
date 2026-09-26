<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;

/**
 * Ingredient cost for a leaf (non-nested-formulation) formulation line,
 * from stock valuation — docs/domain/formulations.md "Cost of one output
 * unit": "not from a price list". v1 supports exactly one configuration:
 * AVCO valuation with a company-wide (COMPANY_ITEM) pool, since that's the
 * only case with one unambiguous current cost. Anything else fails loudly
 * rather than guessing which warehouse/batch pool or which FIFO layer a
 * pure recipe (not tied to any warehouse or batch) should use — a real
 * boundary, not an oversight; see docs/phases/phase-3-estimating-core.md.
 */
final class IngredientCostResolver
{
    public function __invoke(Item $item): BigDecimal
    {
        if ($item->valuation_method !== ValuationMethod::Avco) {
            throw new DomainInvariantException(
                "Formulation costing only supports AVCO-valued ingredients today; {$item->code} is {$item->valuation_method->value}.",
                field: 'input_item_id',
            );
        }

        $pool = AvcoPool::query()
            ->where('item_id', $item->id)
            ->where('scope', AvcoPoolScope::CompanyItem)
            ->first();

        if ($pool !== null) {
            return BigDecimal::of($pool->avg_unit_cost);
        }

        $hasAnyPool = AvcoPool::query()->where('item_id', $item->id)->exists();

        if ($hasAnyPool) {
            throw new DomainInvariantException(
                "{$item->code}'s AVCO pool is scoped by warehouse or batch; formulation costing needs ".
                'a single company-wide cost, which is not supported yet.',
                field: 'input_item_id',
            );
        }

        // Never received any stock — a legitimate state, not an error: the
        // cost simply isn't known yet.
        return BigDecimal::zero();
    }
}
