<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources\Costing;

use App\Modules\Estimating\Http\Resources\AssemblyComponentResource;
use App\Modules\Estimating\Support\AssemblyComponentBreakdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssemblyComponentBreakdown */
class AssemblyCostComponentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['component' => new AssemblyComponentResource($this->component),
            'resolved_quantity' => (string) $this->resolvedQuantity,
            'cost' => new CostResolutionResource($this->cost), 'cost_source' => $this->costSource];
    }
}
