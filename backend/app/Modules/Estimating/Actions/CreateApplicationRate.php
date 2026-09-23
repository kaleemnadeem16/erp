<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Support\ApplicationRateCombinationGuard;
use Illuminate\Support\Facades\DB;

final class CreateApplicationRate
{
    public function __construct(private readonly ApplicationRateCombinationGuard $guardCombination) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): ApplicationRate
    {
        $dimensionValueIds = $attributes['dimension_value_ids'] ?? [];
        unset($attributes['dimension_value_ids']);

        return DB::transaction(function () use ($attributes, $dimensionValueIds): ApplicationRate {
            ($this->guardCombination)($attributes['item_id'], $attributes['work_uom_id'], $dimensionValueIds);

            $rate = ApplicationRate::create($attributes);
            $rate->dimensionValues()->sync($dimensionValueIds);

            return $rate->refresh()->load('dimensionValues');
        });
    }
}
