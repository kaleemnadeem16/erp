<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\RateDimension;
use Illuminate\Support\Facades\DB;

final class ArchiveRateDimension
{
    public function __invoke(RateDimension $rateDimension, User $actor): void
    {
        if (! $actor->can('estimating.rate-dimension.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage rate dimensions.');
        }

        DB::transaction(fn () => $rateDimension->delete());
    }
}
