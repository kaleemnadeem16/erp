<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Support\FormulationStatus;

/**
 * docs/domain/formulations.md "Customer-specific formulations": the
 * customer's own formulation for this output item, if one exists, else the
 * company default (customer_id NULL). An ARCHIVED formulation never
 * resolves, even with an ACTIVE version still underneath — see
 * FormulationStatus.
 */
final class ResolveFormulation
{
    public function __invoke(int $outputItemId, ?int $customerId): Formulation
    {
        if ($customerId !== null) {
            $customerFormulation = $this->candidate($outputItemId, $customerId);

            if ($customerFormulation !== null) {
                return $customerFormulation;
            }
        }

        $default = $this->candidate($outputItemId, null);

        if ($default !== null) {
            return $default;
        }

        throw new DomainInvariantException(
            $customerId !== null
                ? 'No formulation resolves for this output item, for this customer or as a company default.'
                : 'No formulation resolves for this output item.',
            field: 'output_item_id',
        );
    }

    private function candidate(int $outputItemId, ?int $customerId): ?Formulation
    {
        return Formulation::query()
            ->where('output_item_id', $outputItemId)
            ->where('customer_id', $customerId)
            ->where('status', '!=', FormulationStatus::Archived)
            ->whereHas('activeVersion')
            ->with('activeVersion.lines')
            ->first();
    }
}
