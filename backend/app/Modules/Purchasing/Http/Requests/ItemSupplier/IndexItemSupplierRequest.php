<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\ItemSupplier;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Purchasing\Support\PurchasingReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class IndexItemSupplierRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return PurchasingReadAccess::itemSuppliers($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:item_id,supplier_account_id,active,preferred,search,supplier_item_code,lead_time_min,lead_time_max,moq_min,moq_max'],
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.supplier_account_id' => ['sometimes', 'integer', 'min:1'],
            'filter.active' => ['sometimes', 'boolean'],
            'filter.preferred' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.supplier_item_code' => ['sometimes', 'string', 'max:255'],
            'filter.lead_time_min' => ['sometimes', 'integer', 'min:0'],
            'filter.lead_time_max' => ['sometimes', 'integer', 'min:0'],
            'filter.moq_min' => ['sometimes', 'numeric', 'min:0'],
            'filter.moq_max' => ['sometimes', 'numeric', 'min:0'],
        ], $this->listQueryRules(
            ['priority', 'supplier_item_code', 'lead_time_days', 'minimum_order_qty', 'active', 'created_at', 'updated_at'],
            ['item', 'supplierAccount', 'purchaseUom']
        ));
    }
}
