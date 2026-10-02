<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Support\ApplicationRateCombinationGuard;
use Illuminate\Support\Facades\DB;

final class UpdateApplicationRate
{
    public function __construct(private readonly ApplicationRateCombinationGuard $guardCombination) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(ApplicationRate $applicationRate, array $attributes, User $actor): ApplicationRate
    {
        if (! $actor->can('estimating.application-rate.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage application rates.');
        }

        $dimensionValueIds = array_key_exists('dimension_value_ids', $attributes)
            ? $attributes['dimension_value_ids']
            : null;
        unset($attributes['dimension_value_ids']);

        return DB::transaction(function () use ($applicationRate, $attributes, $dimensionValueIds): ApplicationRate {
            if ($dimensionValueIds !== null) {
                ($this->guardCombination)($applicationRate->item_id, $applicationRate->work_uom_id, $dimensionValueIds, $applicationRate->id);
                $applicationRate->dimensionValues()->sync($dimensionValueIds);
            }

            $applicationRate->update($attributes);

            return $applicationRate->refresh()->load('dimensionValues');
        });
    }
}
