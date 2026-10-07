<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\RecipeReadAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssemblyVersion */
class AssemblyVersionResource extends JsonResource
{
    /** @return array<int|string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AssemblyVersion $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'assembly_id' => $row->assembly_id,
            'version_no' => $row->version_no,
            'effective_from' => $row->effective_from?->toDateString(),
            'effective_to' => $row->effective_to?->toDateString(),
            'status' => $row->status->value,
            'notes' => $row->notes,
            // The component quantities are the assembly's recipe: shown to recipe readers only (audit A9).
            $this->mergeWhen(RecipeReadAccess::allows($request->user()), [
                'components' => $this->whenLoaded('components', fn () => AssemblyComponentResource::collection($row->components)),
            ]),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
