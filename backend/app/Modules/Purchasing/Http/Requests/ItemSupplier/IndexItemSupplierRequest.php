<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\ItemSupplier;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexItemSupplierRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:item_id,supplier_account_id,active,preferred'],
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
            'filter.supplier_account_id' => ['sometimes', 'integer', 'min:1'],
            'filter.active' => ['sometimes', 'boolean'],
            'filter.preferred' => ['sometimes', 'boolean'],
        ], $this->listQueryRules(['priority', 'created_at'], ['item', 'supplierAccount', 'purchaseUom']));
    }
}
