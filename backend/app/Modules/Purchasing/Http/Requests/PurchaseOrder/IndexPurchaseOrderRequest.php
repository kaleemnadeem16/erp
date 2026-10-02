<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use App\Modules\Purchasing\Support\PurchasingReadAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPurchaseOrderRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        $requiresCommercial = ltrim($this->string('sort')->toString(), '-') === 'total_amount'
            || $this->filled('filter.total_min')
            || $this->filled('filter.total_max');

        return PurchasingReadAccess::purchaseOrders($this->user())
            && (! $requiresCommercial || PurchasingCommercialAccess::canView($this->user()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:supplier_account_id,supplier_site_id,status,currency_id,item_id,order_date_from,order_date_to,expected_delivery_from,expected_delivery_to,issued_from,issued_to,total_min,total_max,search'],
            'filter.supplier_account_id' => ['sometimes', 'integer', 'min:1'],
            'filter.supplier_site_id' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', Rule::enum(PurchaseOrderStatus::class)],
            'filter.currency_id' => ['sometimes', 'integer', 'min:1'],
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.order_date_from' => ['sometimes', 'date'],
            'filter.order_date_to' => ['sometimes', 'date', 'after_or_equal:filter.order_date_from'],
            'filter.expected_delivery_from' => ['sometimes', 'date'],
            'filter.expected_delivery_to' => ['sometimes', 'date', 'after_or_equal:filter.expected_delivery_from'],
            'filter.issued_from' => ['sometimes', 'date'],
            'filter.issued_to' => ['sometimes', 'date', 'after_or_equal:filter.issued_from'],
            'filter.total_min' => ['sometimes', 'numeric', 'min:0'],
            'filter.total_max' => ['sometimes', 'numeric', 'min:0', Rule::when($this->filled('filter.total_min'), ['gte:filter.total_min'])],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['number', 'order_date', 'expected_delivery_date', 'total_amount', 'created_at'], ['lines']));
    }
}
