<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\RateDimension;
use Illuminate\Support\Facades\DB;

final class UpdateRateDimension
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(RateDimension $rateDimension, array $attributes, User $actor): RateDimension
    {
        if (! $actor->can('estimating.rate-dimension.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage rate dimensions.');
        }

        return DB::transaction(function () use ($rateDimension, $attributes): RateDimension {
            $rateDimension->update($attributes);

            return $rateDimension->refresh();
        });
    }
}
