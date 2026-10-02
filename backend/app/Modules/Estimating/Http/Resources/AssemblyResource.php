<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\Assembly;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Assembly */
class AssemblyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Assembly $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'description' => $row->description,
            'work_uom_id' => $row->work_uom_id,
            'assembly_group_id' => $row->assembly_group_id,
            'margin_type' => $this->when(
                $request->user()?->can('estimating.assembly.manage') || $request->user()?->can('estimating.internal_cost.view'),
                $row->margin_type->value,
            ),
            'margin_pct' => $this->when(
                $request->user()?->can('estimating.assembly.manage') || $request->user()?->can('estimating.internal_cost.view'),
                (string) $row->margin_pct,
            ),
            'status' => $row->status->value,
            'work_uom' => $this->whenLoaded('workUom', fn () => ['id' => $row->workUom->id, 'code' => $row->workUom->code, 'name' => $row->workUom->name]),
            'assembly_group' => $this->whenLoaded('assemblyGroup', fn () => $row->assemblyGroup ? new AssemblyGroupResource($row->assemblyGroup) : null),
            'versions' => $this->whenLoaded('versions', fn () => AssemblyVersionResource::collection($row->versions)),
            'active_version' => $this->whenLoaded('activeVersion', fn () => $row->activeVersion ? new AssemblyVersionResource($row->activeVersion) : null),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
