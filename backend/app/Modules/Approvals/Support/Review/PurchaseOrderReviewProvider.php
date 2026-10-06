<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support\Review;

use App\Models\Uom;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\CurrentCompany;
use Brick\Math\BigDecimal;

/** Audit adapter: confined to Approvals; normal Purchasing endpoints and permissions are unchanged. */
final class PurchaseOrderReviewProvider implements ApprovalReviewProvider
{
    private const HEADER = ['id', 'supplier_account_id', 'supplier_site_id', 'currency_id', 'fx_policy', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_rate_source', 'order_date', 'expected_delivery_date', 'notes', 'replaces_purchase_order_id'];

    private const LINE = ['id', 'line_number', 'item_id', 'description', 'supplier_item_code', 'item_packaging_id', 'supplier_offer_id', 'package_name', 'package_code', 'order_unit_label', 'price_uom_id', 'price_unit_label', 'price_quantity', 'price_stock_factor', 'priced_qty', 'minimum_order_qty', 'order_multiple', 'ordering_exception_reason', 'ordered_qty', 'purchase_uom_id', 'ordered_stock_qty', 'stock_uom_id', 'uom_factor', 'unit_price', 'discount_amount', 'tax_amount', 'line_amount', 'base_unit_price', 'base_discount_amount', 'base_tax_amount', 'base_line_amount', 'expected_delivery_date', 'qa_required'];

    public function subjectType(): string
    {
        return 'PURCHASE_ORDER';
    }

    public function fingerprint(int $subjectId): string
    {
        return $this->digest($this->order($subjectId));
    }

    private function order(int $id): PurchaseOrder
    {
        $order = PurchaseOrder::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $order->load(['supplierAccount.party', 'supplierSite.partySite', 'currency', 'lines.item', 'lines.purchaseUom', 'lines.stockUom']);

        return $order;
    }

    private function digest(PurchaseOrder $order): string
    {
        return hash('sha256', json_encode([
            'header' => $order->only(self::HEADER),
            'payment_terms_days' => $order->supplierAccount->payment_terms_days,
            'supplier_address' => $order->supplierSite?->partySite?->only(['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code']),
            'lines' => $order->lines->map(fn ($line): array => $line->only(self::LINE))->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function capture(int $subjectId): array
    {
        $order = $this->order($subjectId);
        $fields = fn (array $values): array => collect($values)->map(fn ($value, $label): array => ['label' => $label, 'value' => $value === null ? null : (string) $value])->values()->all();
        $section = fn (string $title, array $values): array => ['title' => $title, 'fields' => $fields($values)];
        $units = Uom::query()->whereIn('id', $order->lines->pluck('price_uom_id')->filter()->all())->pluck('code', 'id');
        $operational = [$section('Purchase order', [
            'Document' => $order->number ?? 'Draft #'.$order->id,
            'Supplier' => $order->supplierAccount->party->name, 'Supplier code' => $order->supplierAccount->code,
            'Order date' => $order->order_date->toDateString(), 'Expected delivery' => $order->expected_delivery_date?->toDateString(),
            'Payment terms (days)' => $order->supplierAccount->payment_terms_days,
            'Supplier site' => $order->supplierSite?->partySite?->name,
            'Supplier address' => implode(', ', array_filter($order->supplierSite?->partySite?->only(['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code']) ?? [])),
        ])];
        $commercial = [$section('Commercial terms', [
            'Currency' => $order->currency->code, 'Base currency' => app(CurrentCompany::class)->baseCurrencyCode(), 'FX rate' => $order->fx_rate,
            'FX date' => $order->fx_rate_date->toDateString(), 'FX type' => $order->fx_rate_type,
            'FX source' => $order->fx_rate_source, 'Order notes' => $order->notes,
            'Order total' => (string) $order->lines->reduce(fn (BigDecimal $sum, $line): BigDecimal => $sum->plus($line->line_amount), BigDecimal::zero())->toScale(4),
            'Base total' => (string) $order->lines->reduce(fn (BigDecimal $sum, $line): BigDecimal => $sum->plus($line->base_line_amount), BigDecimal::zero())->toScale(4),
        ])];
        foreach ($order->lines as $line) {
            $title = 'Line '.$line->line_number;
            $operational[] = $section($title.' — items and delivery', [
                'Item' => $line->item_code ?? $line->item->code, 'Item name' => $line->item->name,
                'Description / specification' => $line->description, 'Supplier item code' => $line->supplier_item_code,
                'Ordered quantity' => $line->ordered_qty, 'Order unit' => $line->order_unit_label ?? $line->purchaseUom?->code,
                'Stock quantity' => $line->ordered_stock_qty, 'Stock unit' => $line->stockUom?->code,
                'Conversion factor' => $line->uom_factor, 'Package' => $line->package_name,
                'Minimum order quantity' => $line->minimum_order_qty, 'Order multiple' => $line->order_multiple,
                'Expected delivery' => $line->expected_delivery_date?->toDateString(), 'QA required' => $line->qa_required ? 'Yes' : 'No',
            ]);
            $commercial[] = $section($title.' — pricing', [
                'Price unit' => $line->price_unit_label ?? $units->get($line->price_uom_id) ?? $line->purchaseUom?->code, 'Price quantity' => $line->price_quantity ?? '1.0000',
                'Price stock factor' => $line->price_stock_factor ?? $line->uom_factor, 'Priced quantity' => $line->priced_qty ?? $line->ordered_qty,
                'Unit price' => $line->unit_price, 'Discount' => $line->discount_amount,
                'Tax' => $line->tax_amount, 'Line total' => $line->line_amount,
                'Base unit price' => $line->base_unit_price, 'Base discount' => $line->base_discount_amount,
                'Base tax' => $line->base_tax_amount, 'Base line total' => $line->base_line_amount,
                'Ordering exception reason' => $line->ordering_exception_reason,
            ]);
        }

        return ['schema_version' => 1, 'company_id' => $order->company_id, 'subject_id' => $order->id,
            'captured_at' => now()->toIso8601String(), 'fingerprint' => $this->digest($order),
            'operational' => $operational, 'commercial' => $commercial];
    }
}
