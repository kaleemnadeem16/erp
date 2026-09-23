<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Models\RateDimension;
use App\Modules\Estimating\Models\RateDimensionValue;
use App\Modules\Estimating\Support\ApplicationRateResolution;
use App\Modules\Estimating\Support\ResolutionMethod;
use Illuminate\Support\Collection;

/**
 * Implements docs/domain/application-rates.md#resolution:
 *
 *   1. Exact match on all specified dimension values
 *   2. Match on required dimensions only, ignoring optional ones
 *   3. The rate flagged is_default
 *   4. No rate → error, do not silently assume 1:1
 *
 * Step 4 is why this throws rather than returning null — that doc's own
 * "Silent fallback" warning: a missing rate must fail loudly.
 *
 * Tie-break for step 2 (not specified by the domain doc, since an exact
 * duplicate combination is already blocked at write time by
 * ApplicationRateCombinationGuard): among several required-only matches,
 * prefer the one also matching the most optional dimensions — the more
 * specifically configured rate — then the lowest id.
 *
 * A rate with zero dimension tags is never itself an exact/required-only
 * candidate, even against an empty request — untagged is what makes a rate
 * eligible for is_default in the first place, and letting it also win step 1
 * would silently blur "this matched the specific combination requested"
 * into "this was the fallback," which is exactly the distinction the UI
 * needs to show honestly (see application-rates.md's "Silent fallback").
 */
final class ResolveApplicationRate
{
    /** @param list<int> $dimensionValueIds */
    public function __invoke(int $itemId, int $workUomId, array $dimensionValueIds): ApplicationRateResolution
    {
        $requestedSet = collect($dimensionValueIds)->unique()->sort()->values()->all();

        $candidates = ApplicationRate::query()
            ->where('item_id', $itemId)
            ->where('work_uom_id', $workUomId)
            ->with('dimensionValues:id,rate_dimension_id')
            ->get();

        $taggedCandidates = $candidates->filter(fn (ApplicationRate $rate) => $rate->dimensionValues->isNotEmpty());

        $exact = $taggedCandidates->first(
            fn (ApplicationRate $rate) => $rate->dimensionValues->pluck('id')->sort()->values()->all() === $requestedSet
        );

        if ($exact !== null) {
            return new ApplicationRateResolution($exact, ResolutionMethod::ExactMatch);
        }

        $requiredMatch = $this->bestRequiredOnlyMatch($taggedCandidates, $requestedSet);

        if ($requiredMatch !== null) {
            return new ApplicationRateResolution($requiredMatch, ResolutionMethod::RequiredOnlyMatch);
        }

        $default = $candidates->firstWhere('is_default', true);

        if ($default !== null) {
            return new ApplicationRateResolution($default, ResolutionMethod::Default);
        }

        throw new DomainInvariantException(
            'No application rate resolves for this item, work unit and combination of dimension values, and no default rate is configured.',
            field: 'dimension_value_ids',
        );
    }

    /**
     * @param  Collection<int, ApplicationRate>  $candidates
     * @param  list<int>  $requestedSet
     */
    private function bestRequiredOnlyMatch(Collection $candidates, array $requestedSet): ?ApplicationRate
    {
        $requiredDimensionIds = RateDimension::query()->where('is_required', true)->pluck('id')->all();

        if ($requiredDimensionIds === []) {
            return null;
        }

        $requiredRequestedValueIds = RateDimensionValue::query()
            ->whereIn('id', $requestedSet)
            ->whereIn('rate_dimension_id', $requiredDimensionIds)
            ->pluck('id')->sort()->values()->all();

        return $candidates
            ->filter(function (ApplicationRate $rate) use ($requiredDimensionIds, $requiredRequestedValueIds) {
                $rateValueIdsOnRequired = $rate->dimensionValues
                    ->whereIn('rate_dimension_id', $requiredDimensionIds)
                    ->pluck('id')->sort()->values()->all();

                return $rateValueIdsOnRequired === $requiredRequestedValueIds;
            })
            ->sortByDesc(fn (ApplicationRate $rate) => $rate->dimensionValues->count())
            ->first();
    }
}
