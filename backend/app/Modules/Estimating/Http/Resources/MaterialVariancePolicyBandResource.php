<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\MaterialVariancePolicyBand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MaterialVariancePolicyBand */
class MaterialVariancePolicyBandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MaterialVariancePolicyBand $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'direction' => $row->direction->value,
            'measure' => $row->measure->value,
            'threshold' => $row->threshold,
            'uom_id' => $row->uom_id,
            'uom' => $this->whenLoaded('uom', fn () => $row->uom === null ? null : ['id' => $row->uom->id, 'code' => $row->uom->code, 'name' => $row->uom->name]),
            'effect' => $row->effect->value,
            'effect_label' => $row->effect->label(),
        ];
    }
}
