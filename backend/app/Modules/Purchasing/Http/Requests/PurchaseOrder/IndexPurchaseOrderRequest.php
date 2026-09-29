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
        return PurchasingReadAccess::purchaseOrders($this->user())
            && (ltrim($this->string('sort')->toString(), '-') !== 'total_amount'
                || PurchasingCommercialAccess::canView($this->user()));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:supplier_account_id,status,search'],
            'filter.supplier_account_id' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', Rule::enum(PurchaseOrderStatus::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['number', 'order_date', 'expected_delivery_date', 'total_amount', 'created_at'], ['lines']));
    }
}
