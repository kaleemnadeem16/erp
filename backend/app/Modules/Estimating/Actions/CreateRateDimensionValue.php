<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\RateDimension;
use App\Modules\Estimating\Models\RateDimensionValue;
use Illuminate\Support\Facades\DB;

final class CreateRateDimensionValue
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(RateDimension $rateDimension, array $attributes, User $actor): RateDimensionValue
    {
        if (! $actor->can('estimating.rate-dimension.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage rate dimension values.');
        }

        return DB::transaction(fn () => $rateDimension->values()->create($attributes)->refresh());
    }
}
