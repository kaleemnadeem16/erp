<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\Formulation;
use App\Modules\Inventory\Http\Resources\ItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Formulation */
class FormulationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Formulation $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'output_item_id' => $row->output_item_id,
            'code' => $row->code,
            'name' => $row->name,
            'output_qty' => $row->output_qty,
            'output_uom_id' => $row->output_uom_id,
            'customer_id' => $row->customer_id,
            'status' => $row->status,
            'output_item' => $this->whenLoaded('outputItem', fn () => new ItemResource($row->outputItem)),
            'output_uom' => $this->whenLoaded('outputUom', fn () => ['id' => $row->outputUom->id, 'code' => $row->outputUom->code, 'name' => $row->outputUom->name]),
            'customer' => $this->whenLoaded('customer', fn () => $row->customer === null ? null : ['id' => $row->customer->id, 'code' => $row->customer->code]),
            'versions' => $this->whenLoaded('versions', fn () => FormulationVersionResource::collection($row->versions)),
            'active_version' => $this->whenLoaded('activeVersion', fn () => $row->activeVersion === null ? null : new FormulationVersionResource($row->activeVersion)),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
