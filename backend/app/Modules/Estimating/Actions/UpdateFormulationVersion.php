<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\FormulationVersion;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Estimating\Support\FormulationUnitGuard;
use App\Modules\Estimating\Support\FormulationVersionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Only a DRAFT version may have its lines replaced — once ACTIVE or
 * SUPERSEDED it is frozen, see docs/domain/formulations.md "Versioning and
 * immutability". `notes` is metadata and updatable regardless.
 */
final class UpdateFormulationVersion
{
    public function __construct(
        private readonly FormulationCycleGuard $cycleGuard,
        private readonly FormulationUnitGuard $unitGuard,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(FormulationVersion $version, array $attributes, User $actor): FormulationVersion
    {
        if (! $actor->can('estimating.formulation.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage formulation versions.');
        }

        $lines = array_key_exists('lines', $attributes) ? $attributes['lines'] : null;
        unset($attributes['lines']);

        if ($lines !== null && $version->status !== FormulationVersionStatus::Draft) {
            throw new DomainInvariantException(
                'Only a DRAFT formulation version may have its lines changed; create a new version instead.',
                field: 'lines',
            );
        }

        if ($lines !== null) {
            ($this->cycleGuard)(
                $version->formulation->output_item_id,
                array_map(static fn (array $line): int => (int) $line['input_item_id'], $lines),
            );
            ($this->unitGuard)(
                $version->formulation->output_item_id,
                $version->formulation->output_uom_id,
                $lines,
            );
        }

        return DB::transaction(function () use ($version, $attributes, $lines): FormulationVersion {
            if ($lines !== null) {
                $version->lines()->delete();
                foreach ($lines as $sequence => $line) {
                    $version->lines()->create([
                        'input_item_id' => $line['input_item_id'],
                        'qty' => $line['qty'],
                        'uom_id' => $line['uom_id'],
                        'wastage_pct' => $line['wastage_pct'] ?? '0',
                        'sequence' => $line['sequence'] ?? $sequence,
                    ]);
                }
            }

            $version->update($attributes);

            return $version->refresh()->load('lines');
        });
    }
}
