<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GoodsReceiptLine */
class GoodsReceiptLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'purchase_order_line_id' => $this->purchase_order_line_id,
            'item_id' => $this->item_id, 'location_id' => $this->location_id,
            'received_qty' => $this->received_qty, 'received_uom_id' => $this->received_uom_id,
            'stock_qty' => $this->stock_qty, 'stock_uom_id' => $this->stock_uom_id, 'uom_factor' => $this->uom_factor,
            'provisional_unit_cost' => $this->provisional_unit_cost, 'currency_id' => $this->currency_id,
            'fx_rate' => $this->fx_rate, 'base_unit_cost' => $this->base_unit_cost,
            'fx_rate_date' => $this->fx_rate_date->format('Y-m-d'), 'fx_rate_type' => $this->fx_rate_type,
            'fx_rate_source' => $this->fx_rate_source, 'batch_no' => $this->batch_no, 'serial_no' => $this->serial_no,
        ];
    }
}
