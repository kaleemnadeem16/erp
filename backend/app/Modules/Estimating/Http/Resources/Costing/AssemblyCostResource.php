<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources\Costing;

use App\Modules\Estimating\Support\AssemblyCostBreakdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssemblyCostBreakdown */
class AssemblyCostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'version_id' => $this->version->id, 'cost' => new CostResolutionResource($this->cost),
            'cost_by_kind' => [
                'material' => new CostResolutionResource($this->costByKind['MATERIAL']),
                'labour' => new CostResolutionResource($this->costByKind['LABOUR']),
                'plant' => new CostResolutionResource($this->costByKind['PLANT']),
                'subcontract' => new CostResolutionResource($this->costByKind['SUBCONTRACT']),
            ],
            'labour_hours' => (string) $this->labourHours,
            'direct_cost' => new CostResolutionResource($this->directCost),
            'overhead' => ['method' => $this->overheadMethod->value, 'policy_id' => $this->overheadPolicy?->id,
                'driver_type' => $this->overheadPolicy?->driver_type->value, 'rate' => $this->overheadPolicy?->rate,
                'cost' => new CostResolutionResource($this->overheadCost)],
            'sell_per_unit' => $this->sellPerUnit === null ? null : (string) $this->sellPerUnit,
            'components' => AssemblyCostComponentResource::collection($this->components),
        ];
    }
}
