<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\AssemblyStatus;
use App\Modules\Estimating\Support\AssemblyVersionStatus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Makes a DRAFT version the assembly's live recipe: supersedes whatever was
 * previously ACTIVE (closing its effective period the moment this one
 * opens, so assembly_versions_no_overlap never fires here) and marks the
 * assembly itself ACTIVE. The only action allowed to change a version's
 * status/effective dates — see docs/domain/assemblies.md "Versioning".
 * Mirrors ActivateFormulationVersion; no cycle guard is needed here —
 * assembly components never reference other assemblies, only items/
 * formulations/application rates, and formulation-level cycles are already
 * guarded entirely within Formulation's own domain.
 */
final class ActivateAssemblyVersion
{
    public function __invoke(AssemblyVersion $version): AssemblyVersion
    {
        if ($version->status !== AssemblyVersionStatus::Draft) {
            throw new DomainInvariantException(
                'Only a DRAFT assembly version can be activated.',
                field: 'status',
            );
        }

        if ($version->components()->doesntExist()) {
            throw new DomainInvariantException(
                'An assembly version needs at least one component before it can be activated.',
                field: 'components',
            );
        }

        return DB::transaction(function () use ($version): AssemblyVersion {
            $today = Date::today()->toDateString();

            $version->assembly->activeVersion?->update([
                'status' => AssemblyVersionStatus::Superseded,
                'effective_to' => $today,
            ]);

            $version->update([
                'status' => AssemblyVersionStatus::Active,
                'effective_from' => $today,
                'effective_to' => null,
            ]);

            if ($version->assembly->status === AssemblyStatus::Draft) {
                $version->assembly->update(['status' => AssemblyStatus::Active]);
            }

            return $version->refresh()->load('components');
        });
    }
}
