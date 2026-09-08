<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\RecalculationExecution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RecalculationExecution */
class RecalculationExecutionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecalculationExecution $execution */
        $execution = $this->resource;

        return [
            'id' => $execution->id,
            'impact_version_id' => $execution->impact_version_id,
            'stock_move_id' => $execution->stock_move_id,
            'executed_by' => $execution->executed_by,
            'executed_at' => $execution->executed_at->toIso8601String(),
        ];
    }
}
