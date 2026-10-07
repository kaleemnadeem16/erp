<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\SupplierOffer;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupplierOffer */
class SupplierOfferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'item_supplier_id' => $this->item_supplier_id, 'item_packaging_id' => $this->item_packaging_id, 'purchase_uom_id' => $this->purchase_uom_id, 'supplier_item_code' => $this->supplier_item_code, 'preferred' => $this->preferred, 'minimum_order_qty' => $this->minimum_order_qty, 'order_multiple' => $this->order_multiple, 'lead_time_days' => $this->lead_time_days, 'unit_price' => $this->when(PurchasingCommercialAccess::canView($request->user()), fn () => $this->unit_price), 'currency_id' => $this->when(PurchasingCommercialAccess::canView($request->user()), fn () => $this->currency_id), 'price_uom_id' => $this->when(PurchasingCommercialAccess::canView($request->user()), fn () => $this->price_uom_id), 'price_quantity' => $this->when(PurchasingCommercialAccess::canView($request->user()), fn () => $this->price_quantity), 'valid_from' => $this->valid_from?->format('Y-m-d'), 'valid_to' => $this->valid_to?->format('Y-m-d'), 'active' => $this->active];
    }
}
