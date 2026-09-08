<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\InventoryCutoverBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryCutoverBatch */
class InventoryCutoverBatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InventoryCutoverBatch $batch */
        $batch = $this->resource;

        return [
            'id' => $batch->id,
            'inventory_cutover_id' => $batch->inventory_cutover_id,
            'import_batch_id' => $batch->import_batch_id,
            'entity_type' => $batch->entity_type,
            'status' => $batch->status->value,
            'source_record_count' => $batch->source_record_count,
            'accepted_record_count' => $batch->accepted_record_count,
            'rejected_record_count' => $batch->rejected_record_count,
            'source_quantity' => $batch->source_quantity,
            'source_value' => $batch->source_value,
            'base_source_value' => $batch->base_source_value,
            'source_facts' => InventorySourceFactResource::collection($this->whenLoaded('sourceFacts')),
        ];
    }
}
