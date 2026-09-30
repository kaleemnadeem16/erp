<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Exceptions\DomainInvariantException;

/**
 * The write-side rules of ADR 0054 that must hold however a policy arrives — the HTTP request
 * validates them too, but an Action can be called from a seeder, an import or a test, so the rule
 * is repeated here rather than trusted to the caller.
 */
final class VariancePolicyGuard
{
    /**
     * @param  list<array<string, mixed>>  $bands
     */
    public static function assertWritable(array $bands, VarianceEffect|string|null $minimumEffect): void
    {
        $effects = array_map(fn (array $band): string => (string) $band['effect'], $bands);
        if ($minimumEffect !== null) {
            $effects[] = $minimumEffect instanceof VarianceEffect ? $minimumEffect->value : $minimumEffect;
        }

        foreach ($effects as $effect) {
            if (! app(VarianceApprovalAvailability::class)->allows(VarianceEffect::from($effect))) {
                throw new DomainInvariantException(
                    'Approval-required is available once approvals are switched on for material variance (Settings → Approvals).',
                    'bands',
                );
            }
        }

        foreach ($bands as $band) {
            $isQuantity = $band['measure'] === VarianceMeasure::Quantity->value;
            if ($isQuantity !== (($band['uom_id'] ?? null) !== null)) {
                throw new DomainInvariantException(
                    $isQuantity ? 'A quantity band needs a unit.' : 'Only a quantity band has a unit.',
                    'bands',
                );
            }
        }
    }
}
