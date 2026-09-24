<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Models\FormulationVersion;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Estimating\Support\FormulationUnitGuard;
use Illuminate\Support\Facades\DB;

/**
 * Creates version N+1 as DRAFT — always allowed regardless of the current
 * ACTIVE version's state, since this never changes what's live; only
 * ActivateFormulationVersion does that.
 */
final class CreateFormulationVersion
{
    public function __construct(
        private readonly FormulationCycleGuard $cycleGuard,
        private readonly FormulationUnitGuard $unitGuard,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(Formulation $formulation, array $attributes): FormulationVersion
    {
        $lines = $attributes['lines'] ?? [];
        unset($attributes['lines']);

        ($this->cycleGuard)(
            $formulation->output_item_id,
            array_map(static fn (array $line): int => (int) $line['input_item_id'], $lines),
        );
        ($this->unitGuard)(
            $formulation->output_item_id,
            $formulation->output_uom_id,
            $lines,
        );

        return DB::transaction(function () use ($formulation, $attributes, $lines): FormulationVersion {
            $nextVersionNo = (int) $formulation->versions()->max('version_no') + 1;

            $version = $formulation->versions()->create([
                'version_no' => $nextVersionNo,
                'notes' => $attributes['notes'] ?? null,
            ]);

            foreach ($lines as $sequence => $line) {
                $version->lines()->create([
                    'input_item_id' => $line['input_item_id'],
                    'qty' => $line['qty'],
                    'uom_id' => $line['uom_id'],
                    'wastage_pct' => $line['wastage_pct'] ?? '0',
                    'sequence' => $line['sequence'] ?? $sequence,
                ]);
            }

            return $version->refresh()->load('lines');
        });
    }
}
