<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\RateDimension;
use Illuminate\Support\Facades\DB;

final class CreateRateDimension
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): RateDimension
    {
        if (! $actor->can('estimating.rate-dimension.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage rate dimensions.');
        }

        return DB::transaction(fn () => RateDimension::create($attributes)->refresh());
    }
}
