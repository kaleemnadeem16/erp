<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources\Costing;

use App\Modules\Inventory\Support\Costing\CostResolution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CostResolution */
class CostResolutionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Stock identity, on-hand quantities and the fingerprint they move are field-scoped to stock viewers.
        $view = $this->resource->forReader($request->user()?->can('estimating.inventory_availability.view') === true);

        return [
            'schema_version' => 1,
            'status' => $view->status,
            'total_cost' => $view->totalCost === null ? null : (string) $view->totalCost,
            'unit_cost' => $view->unitCost === null ? null : (string) $view->unitCost,
            'known_cost' => $view->knownCost === null ? null : (string) $view->knownCost,
            'amount_basis' => $view->amountBasis,
            'valued_quantity' => (string) $view->valuedQuantity,
            'currency_id' => $view->currencyId,
            'unit_basis' => $view->unitBasis,
            'coverage' => $view->coverage,
            'diagnostics' => $view->diagnostics,
            'source_type' => $view->sourceType,
            'method' => $view->method,
            'scope_requested' => $view->scopeRequested,
            'scope_used' => $view->scopeUsed,
            'scope_changed' => $this->resource->scopeRequested !== $this->resource->scopeUsed,
            'requested_as_of' => $view->requestedAsOf,
            'effective_as_of' => $view->effectiveAsOf,
            'observed_at' => $view->observedAt,
            'basis' => $view->basis,
            'basis_fingerprint' => (string) $view->toArray()['basis_fingerprint'],
        ];
    }
}
