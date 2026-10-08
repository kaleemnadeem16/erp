<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\StockScrap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockScrap */
class StockScrapResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var StockScrap $document */
        $document = $this->resource;

        return [
            'id' => $document->id, 'number' => $document->number, 'status' => $document->status, 'warehouse_id' => $document->warehouse_id, 'reason' => $document->reason,
            'disposal_method' => $document->disposal_method, 'disposal_reference' => $document->disposal_reference,
            'created_by' => $document->created_by, 'created_at' => $document->created_at?->toIso8601String(), 'posted_by' => $document->posted_by,
            'posted_at' => $document->posted_at?->toIso8601String(), 'cancelled_by' => $document->cancelled_by, 'cancelled_at' => $document->cancelled_at?->toIso8601String(),
            'lines' => StockScrapLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
