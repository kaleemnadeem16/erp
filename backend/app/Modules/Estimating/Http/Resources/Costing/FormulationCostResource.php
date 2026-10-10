<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources\Costing;

use App\Modules\Estimating\Support\FormulationExplosion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FormulationExplosion */
class FormulationCostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['version_id' => $this->version->id, 'cost' => new CostResolutionResource($this->cost),
            'lines' => FormulationCostLineResource::collection($this->lines)];
    }
}
