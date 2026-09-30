<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\ResourceRate;
use Illuminate\Support\Facades\DB;

final class DeleteResourceRate
{
    public function __invoke(ResourceRate $resourceRate, User $actor): void
    {
        if (! $actor->can('estimating.resource-rate.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage resource rates.');
        }

        DB::transaction(fn () => $resourceRate->delete());
    }
}
