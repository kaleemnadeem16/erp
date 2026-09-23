<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\FormulationVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FormulationVersion */
class FormulationVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FormulationVersion $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'formulation_id' => $row->formulation_id,
            'version_no' => $row->version_no,
            'effective_from' => $row->effective_from?->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'status' => $row->status,
            'notes' => $row->notes,
            'lines' => $this->whenLoaded('lines', fn () => FormulationLineResource::collection($row->lines)),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
