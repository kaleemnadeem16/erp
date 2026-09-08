<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Uom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UomResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Uom $uom */
        $uom = $this->resource;

        return [
            'id' => $uom->id,
            'code' => $uom->code,
            'name' => $uom->name,
            'dimension' => $uom->dimension->value,
            'factor_to_base' => $uom->factor_to_base,
            'is_base' => $uom->is_base,
            'created_at' => $uom->created_at?->toIso8601String(),
            'updated_at' => $uom->updated_at?->toIso8601String(),
        ];
    }
}
