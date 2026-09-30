<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\AssemblyComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssemblyComponent */
class AssemblyComponentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AssemblyComponent $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'kind' => $row->kind->value,
            'item_id' => $row->item_id,
            'formulation_id' => $row->formulation_id,
            'application_rate_id' => $row->application_rate_id,
            'qty_per_work_unit' => $row->qty_per_work_unit,
            'uom_id' => $row->uom_id,
            'wastage_pct' => $row->wastage_pct,
            'multiplier' => $row->multiplier,
            'rate_override' => $this->when(
                $request->user()?->can('estimating.assembly.manage') || $request->user()?->can('estimating.internal_cost.view'),
                $row->rate_override,
            ),
            'sequence' => $row->sequence,
            'item' => $this->whenLoaded('item', fn () => $row->item === null ? null : [
                'id' => $row->item->id, 'code' => $row->item->code, 'name' => $row->item->name,
            ]),
            'formulation' => $this->whenLoaded('formulation', fn () => $row->formulation === null ? null : [
                'id' => $row->formulation->id, 'code' => $row->formulation->code, 'name' => $row->formulation->name,
            ]),
            'uom' => $this->whenLoaded('uom', fn () => ['id' => $row->uom->id, 'code' => $row->uom->code, 'name' => $row->uom->name]),
        ];
    }
}
