<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\ItemSupplier;

use App\Modules\Purchasing\Models\ItemSupplier;
use App\Rules\Support\CompanyScoped;
use App\Support\CompanyContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ItemSupplier|null $itemSupplier */
        $itemSupplier = $this->route('item_supplier');
        $itemId = $this->input('item_id', $itemSupplier?->item_id);

        return [
            'item_id' => ['sometimes', 'integer', CompanyScoped::exists('items')],
            'supplier_account_id' => [
                'sometimes', 'integer', CompanyScoped::exists('supplier_accounts'),
                Rule::unique('item_suppliers')->ignore($itemSupplier?->id)->where(fn ($query) => $query
                    ->where('company_id', app(CompanyContext::class)->id())->where('item_id', $itemId)),
            ],
            'supplier_item_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'priority' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:32767'],
            'lead_time_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:32767'],
            'minimum_order_qty' => ['sometimes', 'nullable', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999'],
            'purchase_uom_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('uoms')],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
