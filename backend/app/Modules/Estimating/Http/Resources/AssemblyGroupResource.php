<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Resources;

use App\Modules\Estimating\Models\AssemblyGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AssemblyGroup */
class AssemblyGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AssemblyGroup $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'assemblies' => $this->whenLoaded('assemblies', fn () => AssemblyResource::collection($row->assemblies)),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
