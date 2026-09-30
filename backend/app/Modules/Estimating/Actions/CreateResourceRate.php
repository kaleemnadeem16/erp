<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\ResourceRate;
use Illuminate\Support\Facades\DB;

final class CreateResourceRate
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): ResourceRate
    {
        if (! $actor->can('estimating.resource-rate.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage resource rates.');
        }

        return DB::transaction(fn () => ResourceRate::create($attributes)->refresh());
    }
}
