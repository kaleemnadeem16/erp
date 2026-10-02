<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\ApplicationRate;
use Illuminate\Support\Facades\DB;

final class ArchiveApplicationRate
{
    public function __invoke(ApplicationRate $applicationRate, User $actor): void
    {
        if (! $actor->can('estimating.application-rate.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage application rates.');
        }

        DB::transaction(fn () => $applicationRate->delete());
    }
}
