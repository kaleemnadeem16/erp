<?php

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseOrderLine */
class PurchaseOrderLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $received = BigDecimal::of($this->posted_received_stock_qty ?? '0')->toScale(4);
        // draft_held_stock_qty: quantity a still-unposted goods receipt already
        // claims against this line. Excluded from received_stock_qty (nothing has
        // actually landed yet) but must still reduce outstanding_stock_qty, or a
        // second draft can claim the same "open" quantity a first draft is already
        // holding — only caught later, confusingly, when the second one posts.
        $draftHeld = BigDecimal::of($this->draft_held_stock_qty ?? '0')->toScale(4);
        $outstanding = BigDecimal::of($this->ordered_stock_qty)
            ->minus($received)
            ->minus($draftHeld)
            ->minus(BigDecimal::of($this->short_closed_stock_qty));
        if ($outstanding->isNegative()) {
            $outstanding = BigDecimal::zero();
        }
        $outstanding = $outstanding->toScale(4);

        $commercial = PurchasingCommercialAccess::canView($request->user());

        return [
            'id' => $this->id, 'line_number' => $this->line_number,
            'item_id' => $this->item_id, 'item_code' => $this->item_code,
            'description' => $this->description, 'supplier_item_code' => $this->supplier_item_code,
            'item_packaging_id' => $this->item_packaging_id, 'supplier_offer_id' => $this->supplier_offer_id,
            'package_name' => $this->package_name, 'package_code' => $this->package_code, 'order_unit_label' => $this->order_unit_label,
            'price_uom_id' => $this->when($commercial, fn () => $this->price_uom_id),
            'price_unit_label' => $this->when($commercial, fn () => $this->price_unit_label),
            'price_quantity' => $this->when($commercial, fn () => $this->price_quantity),
            'price_stock_factor' => $this->when($commercial, fn () => $this->price_stock_factor),
            'priced_qty' => $this->when($commercial, fn () => $this->priced_qty),
            'minimum_order_qty' => $this->minimum_order_qty, 'order_multiple' => $this->order_multiple,
            'ordering_terms_violated' => ! empty($this->offer_snapshot['constraint_warning']),
            'ordering_exception_reason' => $this->ordering_exception_reason,
            'ordered_qty' => $this->ordered_qty, 'purchase_uom_id' => $this->purchase_uom_id,
            'ordered_stock_qty' => $this->ordered_stock_qty, 'received_stock_qty' => (string) $received,
            'outstanding_stock_qty' => (string) $outstanding, 'stock_uom_id' => $this->stock_uom_id,
            'uom_factor' => $this->uom_factor,
            'unit_price' => $this->when($commercial, fn () => $this->unit_price),
            'discount_amount' => $this->when($commercial, fn () => $this->discount_amount),
            'tax_amount' => $this->when($commercial, fn () => $this->tax_amount),
            'line_amount' => $this->when($commercial, fn () => $this->line_amount),
            'base_unit_price' => $this->when($commercial, fn () => $this->base_unit_price),
            'base_discount_amount' => $this->when($commercial, fn () => $this->base_discount_amount),
            'base_tax_amount' => $this->when($commercial, fn () => $this->base_tax_amount),
            'base_line_amount' => $this->when($commercial, fn () => $this->base_line_amount),
            'expected_delivery_date' => $this->expected_delivery_date?->format('Y-m-d'),
            'qa_required' => $this->qa_required,
            'over_receipt_mode' => $this->over_receipt_mode?->value,
            'over_receipt_tolerance_percent' => $this->over_receipt_tolerance_percent,
            'short_closed_stock_qty' => $this->short_closed_stock_qty, 'source_record_id' => $this->source_record_id,
        ];
    }
}
