<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Support\FormulationStatus;
use Illuminate\Support\Facades\DB;

final class ArchiveFormulation
{
    public function __invoke(Formulation $formulation, User $actor): void
    {
        if (! $actor->can('estimating.formulation.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage formulations.');
        }

        DB::transaction(fn () => $formulation->update(['status' => FormulationStatus::Archived]));
    }
}
