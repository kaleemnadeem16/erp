<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\FormulationVersion;
use App\Modules\Estimating\Support\FormulationVersionStatus;
use Illuminate\Support\Facades\DB;

final class DeleteFormulationVersion
{
    public function __invoke(FormulationVersion $version, User $actor): void
    {
        if (! $actor->can('estimating.formulation.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage formulation versions.');
        }

        if ($version->status !== FormulationVersionStatus::Draft) {
            throw new DomainInvariantException(
                'Only a DRAFT formulation version may be deleted; ACTIVE and SUPERSEDED versions are kept.',
                field: 'status',
            );
        }

        DB::transaction(fn () => $version->delete());
    }
}
