<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPurchaseOrderRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(['filter' => ['sometimes', 'array:supplier_account_id,status'], 'filter.supplier_account_id' => ['sometimes', 'integer', 'min:1'], 'filter.status' => ['sometimes', Rule::enum(PurchaseOrderStatus::class)]], $this->listQueryRules(['number', 'order_date', 'created_at'], ['lines']));
    }
}
