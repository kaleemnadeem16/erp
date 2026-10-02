<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\RateDimensionValue;
use Illuminate\Support\Facades\DB;

final class DeleteRateDimensionValue
{
    public function __invoke(RateDimensionValue $rateDimensionValue, User $actor): void
    {
        if (! $actor->can('estimating.rate-dimension.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage rate dimension values.');
        }

        DB::transaction(fn () => $rateDimensionValue->delete());
    }
}
