<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Inventory\Http\Resources\ItemResource;
use App\Modules\Parties\Http\Resources\SupplierAccountResource;
use App\Modules\Purchasing\Models\ItemSupplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ItemSupplier */
class ItemSupplierResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ItemSupplier $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'item_id' => $row->item_id,
            'supplier_account_id' => $row->supplier_account_id,
            'supplier_item_code' => $row->supplier_item_code,
            'priority' => $row->priority,
            'lead_time_days' => $row->lead_time_days,
            'resolved_lead_time_days' => $row->resolvedLeadTimeDays(),
            'minimum_order_qty' => $row->minimum_order_qty,
            'purchase_uom_id' => $row->purchase_uom_id,
            'active' => $row->active,
            'item' => $this->whenLoaded('item', fn () => new ItemResource($row->item)),
            'supplier_account' => $this->whenLoaded('supplierAccount', fn () => new SupplierAccountResource($row->supplierAccount)),
            'purchase_uom' => $this->whenLoaded('purchaseUom', fn () => $row->purchaseUom === null ? null : [
                'id' => $row->purchaseUom->id,
                'code' => $row->purchaseUom->code,
                'name' => $row->purchaseUom->name,
            ]),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
