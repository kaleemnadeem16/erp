<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\GoodsReceipt;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Modules\Purchasing\Support\PurchasingReadAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexGoodsReceiptRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return PurchasingReadAccess::goodsReceipts($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:supplier_account_id,status,search,posting_date_from,posting_date_to,physical_arrival_from,physical_arrival_to'],
            'filter.supplier_account_id' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', Rule::enum(GoodsReceiptStatus::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.posting_date_from' => ['sometimes', 'date'],
            'filter.posting_date_to' => ['sometimes', 'date'],
            'filter.physical_arrival_from' => ['sometimes', 'date'],
            'filter.physical_arrival_to' => ['sometimes', 'date'],
        ], $this->listQueryRules([
            'number',
            'posting_date',
            'physical_arrival_at',
            'recorded_at',
            'posted_at',
            'status',
            'created_at',
            'updated_at',
        ], ['lines', 'supplierAccount']));
    }
}
