<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\FormulationVersion;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Estimating\Support\FormulationStatus;
use App\Modules\Estimating\Support\FormulationVersionStatus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Makes a DRAFT version the formulation's live recipe: supersedes whatever
 * was previously ACTIVE (closing its effective period the moment this one
 * opens, so formulation_versions_no_overlap never fires here) and marks
 * the formulation itself ACTIVE. The only action allowed to change a
 * version's status/effective dates — see
 * docs/domain/formulations.md "Versioning and immutability".
 *
 * Re-runs the cycle guard here too, not just on create/update: the guard
 * only ever walks *active* sub-formulations (a DRAFT recipe isn't "real"
 * consumption yet), so two formulations built independently — each
 * individually cycle-free while both are still DRAFT — could otherwise
 * each activate successfully and only complete a live cycle on the second
 * activation, with nothing having ever rejected either save.
 */
final class ActivateFormulationVersion
{
    public function __construct(private readonly FormulationCycleGuard $cycleGuard) {}

    public function __invoke(FormulationVersion $version): FormulationVersion
    {
        if ($version->status !== FormulationVersionStatus::Draft) {
            throw new DomainInvariantException(
                'Only a DRAFT formulation version can be activated.',
                field: 'status',
            );
        }

        if ($version->lines()->doesntExist()) {
            throw new DomainInvariantException(
                'A formulation version needs at least one line before it can be activated.',
                field: 'lines',
            );
        }

        ($this->cycleGuard)(
            $version->formulation->output_item_id,
            $version->lines()->pluck('input_item_id')->all(),
        );

        return DB::transaction(function () use ($version): FormulationVersion {
            $today = Date::today()->toDateString();

            $version->formulation->activeVersion?->update([
                'status' => FormulationVersionStatus::Superseded,
                'effective_to' => $today,
            ]);

            $version->update([
                'status' => FormulationVersionStatus::Active,
                'effective_from' => $today,
                'effective_to' => null,
            ]);

            if ($version->formulation->status === FormulationStatus::Draft) {
                $version->formulation->update(['status' => FormulationStatus::Active]);
            }

            return $version->refresh()->load('lines');
        });
    }
}
