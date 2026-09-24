<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\FormulationLine;
use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FormulationLine */
class FormulationLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FormulationLine $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'input_item_id' => $row->input_item_id,
            'qty' => $row->qty,
            'uom_id' => $row->uom_id,
            'wastage_pct' => $row->wastage_pct,
            'sequence' => $row->sequence,
            'input_item' => $this->whenLoaded('inputItem', fn () => new ItemResource($row->inputItem)),
            'uom' => $this->whenLoaded('uom', fn () => [
                'id' => $row->uom->id,
                'code' => $row->uom->code,
                'name' => $row->uom->name,
            ]),
        ];
    }
}
