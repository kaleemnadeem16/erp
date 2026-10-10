<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources\Costing;

use App\Modules\Estimating\Support\FormulationLineBreakdown;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FormulationLineBreakdown */
class FormulationCostLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'line_id' => $this->line->id, 'input_item_id' => $this->line->input_item_id,
            'input_item_code' => $this->line->inputItem->code, 'input_item_name' => $this->line->inputItem->name,
            'qty' => $this->line->qty, 'wastage_pct' => $this->line->wastage_pct,
            'cost' => new CostResolutionResource($this->cost),
            'sub_formulation' => $this->subExplosion === null ? null : new FormulationCostResource($this->subExplosion),
        ];
    }
}
