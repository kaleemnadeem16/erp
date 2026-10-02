<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Estimating\Support\FormulationUnitGuard;
use Illuminate\Support\Facades\DB;

/**
 * Creates the formulation header together with its first version (DRAFT,
 * version_no 1) and lines in one call — a formulation with zero versions
 * has nothing to show and nothing to activate, so there is no useful
 * intermediate state to expose over the API.
 */
final class CreateFormulation
{
    public function __construct(
        private readonly FormulationCycleGuard $cycleGuard,
        private readonly FormulationUnitGuard $unitGuard,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): Formulation
    {
        if (! $actor->can('estimating.formulation.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage formulations.');
        }

        $lines = $attributes['lines'] ?? [];
        unset($attributes['lines']);

        ($this->cycleGuard)(
            (int) $attributes['output_item_id'],
            array_map(static fn (array $line): int => (int) $line['input_item_id'], $lines),
        );
        ($this->unitGuard)(
            (int) $attributes['output_item_id'],
            (int) $attributes['output_uom_id'],
            $lines,
        );

        return DB::transaction(function () use ($attributes, $lines): Formulation {
            $formulation = Formulation::create($attributes)->refresh();

            // ->refresh() matters here, not just on the final return: create()
            // never reads back the status column's DB-level default, so the
            // in-memory model would otherwise see status as null rather than
            // DRAFT for the rest of this method.
            $version = $formulation->versions()->create(['version_no' => 1])->refresh();
            foreach ($lines as $sequence => $line) {
                $version->lines()->create([
                    'input_item_id' => $line['input_item_id'],
                    'qty' => $line['qty'],
                    'uom_id' => $line['uom_id'],
                    'wastage_pct' => $line['wastage_pct'] ?? '0',
                    'sequence' => $line['sequence'] ?? $sequence,
                ]);
            }

            return $formulation->refresh()->load('versions.lines');
        });
    }
}
