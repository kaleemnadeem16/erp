<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GoodsReceiptLine */
class GoodsReceiptLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $commercial = PurchasingCommercialAccess::canView($request->user());

        return [
            'id' => $this->id, 'purchase_order_line_id' => $this->purchase_order_line_id,
            'item_id' => $this->item_id, 'location_id' => $this->location_id,
            'item_packaging_id' => $this->item_packaging_id, 'package_name' => $this->package_name, 'received_package_qty' => $this->received_package_qty,
            'received_qty' => $this->received_qty, 'received_uom_id' => $this->received_uom_id,
            'stock_qty' => $this->stock_qty, 'stock_uom_id' => $this->stock_uom_id, 'uom_factor' => $this->uom_factor,
            'provisional_unit_cost' => $this->when($commercial, fn () => $this->provisional_unit_cost),
            'currency_id' => $this->currency_id,
            'fx_rate' => $this->when($commercial, fn () => $this->fx_rate),
            'base_unit_cost' => $this->when($commercial, fn () => $this->base_unit_cost),
            'fx_rate_date' => $this->when($commercial, fn () => $this->fx_rate_date->format('Y-m-d')),
            'fx_rate_type' => $this->when($commercial, fn () => $this->fx_rate_type),
            'fx_rate_source' => $this->when($commercial, fn () => $this->fx_rate_source),
            'batch_no' => $this->batch_no, 'serial_no' => $this->serial_no,
            'qa_unallocated_qty' => $this->qaUnallocatedQty(), 'qa_unresolved_qty' => $this->qaUnresolvedQty(),
        ];
    }

    /**
     * Relies entirely on GoodsReceiptLine::scopeWithQaAggregates() having been
     * applied by the caller — purchaseOrderLine and both qa_*_allocated_qty sums are
     * read here, never queried, so rendering a collection costs one pair of
     * aggregate queries total instead of one pair per row.
     */
    private function qaRequired(): bool
    {
        return $this->purchaseOrderLine !== null && $this->purchaseOrderLine->qa_required;
    }

    private function qaUnallocatedQty(): ?string
    {
        if (! $this->qaRequired()) {
            return null;
        }

        return (string) BigDecimal::of($this->stock_qty)->minus(BigDecimal::of((string) ($this->qa_root_allocated_qty ?? '0')));
    }

    private function qaUnresolvedQty(): ?string
    {
        if (! $this->qaRequired()) {
            return null;
        }

        return (string) BigDecimal::of($this->stock_qty)->minus(BigDecimal::of((string) ($this->qa_terminal_allocated_qty ?? '0')));
    }
}
