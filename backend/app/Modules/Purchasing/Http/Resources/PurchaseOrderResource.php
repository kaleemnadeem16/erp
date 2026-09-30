<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Parties\Http\Resources\SupplierSiteResource;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalState;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrder */
class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $commercial = PurchasingCommercialAccess::canView($request->user());

        return [
            'id' => $this->id, 'number' => $this->number,
            'supplier_account_id' => $this->supplier_account_id, 'supplier_site_id' => $this->supplier_site_id,
            'supplier_site' => $this->whenLoaded('supplierSite', fn () => $this->supplierSite === null ? null : new SupplierSiteResource($this->supplierSite)),
            'status' => $this->status->value, 'currency_id' => $this->currency_id,
            'fx_policy' => $this->when($commercial, fn () => $this->fx_policy->value),
            'fx_rate' => $this->when($commercial, fn () => $this->fx_rate),
            'fx_rate_date' => $this->when($commercial, fn () => $this->fx_rate_date->format('Y-m-d')),
            'fx_rate_type' => $this->when($commercial, fn () => $this->fx_rate_type),
            'fx_rate_source' => $this->when($commercial, fn () => $this->fx_rate_source),
            'order_date' => $this->order_date->format('Y-m-d'),
            'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'notes' => $this->notes, 'inventory_cutover_id' => $this->inventory_cutover_id,
            'source_system' => $this->source_system, 'source_record_id' => $this->source_record_id,
            'total_amount' => $this->when($commercial && $this->relationLoaded('lines'), fn () => $this->sumLines('line_amount')),
            'base_total_amount' => $this->when($commercial && $this->relationLoaded('lines'), fn () => $this->sumLines('base_line_amount')),
            'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')),
            // Where the order stands with the approval engine; only on the screens that act on one order.
            'approval' => $this->when($request->routeIs('purchase-orders.show', 'purchase-orders.store', 'purchase-orders.update', 'purchase-orders.submit', 'purchase-orders.issue', 'purchase-orders.cancel'), fn () => app(PurchaseOrderApprovalState::class)->for($this->resource)),
            'issued_at' => $this->issued_at?->toIso8601String(), 'closed_at' => $this->closed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(), 'cancellation_reason' => $this->cancellation_reason,
        ];
    }

    private function sumLines(string $column): string
    {
        return (string) $this->lines->reduce(
            fn (BigDecimal $total, $line) => $total->plus(BigDecimal::of($line->{$column})),
            BigDecimal::zero()->toScale(4),
        );
    }
}
