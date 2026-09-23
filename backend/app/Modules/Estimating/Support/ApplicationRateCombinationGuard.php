<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\ApplicationRate;

/**
 * Blocks two application rates for the same item+work unit resolving to the
 * exact same set of dimension values. The resolution algorithm in
 * docs/domain/application-rates.md#resolution assumes "exact match" finds at
 * most one candidate; this is what keeps that assumption true.
 */
final class ApplicationRateCombinationGuard
{
    /** @param list<int> $dimensionValueIds */
    public function __invoke(int $itemId, int $workUomId, array $dimensionValueIds, ?int $ignoreRateId = null): void
    {
        $sorted = collect($dimensionValueIds)->unique()->sort()->values()->all();

        $existing = ApplicationRate::query()
            ->where('item_id', $itemId)
            ->where('work_uom_id', $workUomId)
            ->when($ignoreRateId !== null, fn ($query) => $query->whereKeyNot($ignoreRateId))
            ->with('dimensionValues:id')
            ->get();

        foreach ($existing as $rate) {
            $existingSet = $rate->dimensionValues->pluck('id')->sort()->values()->all();

            if ($existingSet === $sorted) {
                throw new DomainInvariantException(
                    'Another application rate already covers this exact combination of dimension values for this item and work unit.',
                    field: 'dimension_value_ids',
                );
            }
        }
    }
}
