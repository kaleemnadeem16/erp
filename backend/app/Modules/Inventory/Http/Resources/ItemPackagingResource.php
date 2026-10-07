<?php

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Inventory\Models\ItemPackaging;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ItemPackaging */
class ItemPackagingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'item_id' => $this->item_id, 'code' => $this->code, 'name' => $this->name, 'stock_quantity' => $this->stock_quantity, 'quantity_precision' => $this->quantity_precision, 'barcode' => $this->barcode, 'inner_packaging_id' => $this->inner_packaging_id, 'inner_count' => $this->inner_count, 'active' => $this->active];
    }
}
