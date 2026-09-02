<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\PurchaseOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrderLine */
class PurchaseOrderLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'line_number' => $this->line_number, 'item_id' => $this->item_id, 'item_code' => $this->item_code, 'description' => $this->description, 'supplier_item_code' => $this->supplier_item_code, 'ordered_qty' => $this->ordered_qty, 'purchase_uom_id' => $this->purchase_uom_id, 'ordered_stock_qty' => $this->ordered_stock_qty, 'stock_uom_id' => $this->stock_uom_id, 'uom_factor' => $this->uom_factor, 'unit_price' => $this->unit_price, 'discount_amount' => $this->discount_amount, 'tax_amount' => $this->tax_amount, 'line_amount' => $this->line_amount, 'base_unit_price' => $this->base_unit_price, 'base_discount_amount' => $this->base_discount_amount, 'base_tax_amount' => $this->base_tax_amount, 'base_line_amount' => $this->base_line_amount, 'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'), 'qa_required' => $this->qa_required, 'over_receipt_mode' => $this->over_receipt_mode?->value, 'over_receipt_tolerance_percent' => $this->over_receipt_tolerance_percent, 'short_closed_stock_qty' => $this->short_closed_stock_qty];
    }
}
