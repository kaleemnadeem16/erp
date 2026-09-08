<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockBatch */
class StockBatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StockBatch $batch */
        $batch = $this->resource;

        return [
            'id' => $batch->id,
            'item_id' => $batch->item_id,
            'code' => $batch->code,
        ];
    }
}
