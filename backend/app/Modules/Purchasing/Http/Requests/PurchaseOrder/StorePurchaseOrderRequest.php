<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Purchasing\Support\OverReceiptMode;
use App\Modules\Purchasing\Support\PurchaseOrderFxPolicy;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.purchase-order.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'replaces_purchase_order_id' => ['nullable', 'integer', CompanyScoped::exists('purchase_orders')],
            'supplier_account_id' => ['required', 'integer', CompanyScoped::exists('supplier_accounts')],
            'supplier_site_id' => ['nullable', 'integer', Rule::exists('supplier_sites', 'id')->where(fn ($q) => $q->where('supplier_account_id', $this->input('supplier_account_id')))],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'fx_policy' => ['sometimes', Rule::enum(PurchaseOrderFxPolicy::class)],
            'fx_override_reason' => ['nullable', 'string', 'max:2000'],
            'fx_rate' => ['required', 'decimal:0,9', 'gt:0', 'max:9999999999.999999999'],
            'fx_rate_date' => ['required', 'date_format:Y-m-d'], 'fx_rate_type' => ['required', 'string', 'max:50'],
            'fx_rate_source' => ['required', 'string', 'max:100'], 'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Delivery, ownership and terms (ADR 0191). All optional; a warehouse must be one of this company's active ones and a location one of that warehouse's.
            'ship_to_warehouse_id' => ['nullable', 'integer', CompanyScoped::exists('warehouses')->where('active', true)],
            'ship_to_location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('active', true)->where(fn ($q) => $q->where('warehouse_id', $this->shipToWarehouseId()))],
            'buyer_user_id' => ['nullable', 'integer', CompanyScoped::exists('users')->where('status', MemberStatus::Active->value)],
            'payment_terms_days' => ['nullable', 'integer', 'between:0,32767'],
            'shipping_method' => ['nullable', 'string', 'max:100', 'regex:/\S/'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_number' => ['required', 'integer', 'between:1,32767', 'distinct'],
            'lines.*.item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.ordered_qty' => ['required', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999'],
            'lines.*.purchase_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'lines.*.item_packaging_id' => ['nullable', 'integer', CompanyScoped::exists('item_packagings')],
            'lines.*.supplier_offer_id' => ['nullable', 'integer', CompanyScoped::exists('supplier_offers')],
            'lines.*.price_uom_id' => ['nullable', 'integer', CompanyScoped::exists('uoms')],
            'lines.*.price_quantity' => ['sometimes', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999'],
            'lines.*.ordering_exception_reason' => ['nullable', 'string', 'max:2000'],
            'lines.*.unit_price' => ['required', 'decimal:0,4', 'min:0', 'max:999999999999999.9999'],
            'lines.*.discount_amount' => ['sometimes', 'decimal:0,4', 'min:0', 'max:999999999999999.9999'], 'lines.*.tax_amount' => ['sometimes', 'decimal:0,4', 'min:0', 'max:999999999999999.9999'],
            'lines.*.expected_delivery_date' => ['nullable', 'date_format:Y-m-d'], 'lines.*.qa_required' => ['sometimes', 'boolean'],
            'lines.*.over_receipt_mode' => ['nullable', Rule::enum(OverReceiptMode::class)],
            'lines.*.over_receipt_tolerance_percent' => ['nullable', 'decimal:0,4', 'between:0,100'],
        ];
    }

    /** The warehouse the ship-to location is judged against: the one in the request (a draft being created has no other). */
    protected function shipToWarehouseId(): mixed
    {
        return $this->input('ship_to_warehouse_id');
    }
}
