<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'number' => $this->number, 'supplier_account_id' => $this->supplier_account_id, 'supplier_site_id' => $this->supplier_site_id, 'status' => $this->status->value, 'currency_id' => $this->currency_id, 'fx_policy' => $this->fx_policy->value, 'fx_rate' => $this->fx_rate, 'fx_rate_date' => $this->fx_rate_date->format('Y-m-d'), 'fx_rate_type' => $this->fx_rate_type, 'fx_rate_source' => $this->fx_rate_source, 'order_date' => $this->order_date->format('Y-m-d'), 'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'), 'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')), 'issued_at' => $this->issued_at?->toIso8601String(), 'closed_at' => $this->closed_at?->toIso8601String(), 'cancelled_at' => $this->cancelled_at?->toIso8601String(), 'cancellation_reason' => $this->cancellation_reason];
    }
}
