<?php

namespace App\Modules\Inventory\Http\Requests\ItemPackaging;

use Illuminate\Foundation\Http\FormRequest;

class IndexItemPackagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'], 'filter.item_id' => ['sometimes', 'integer'], 'filter.supplier_account_id' => ['sometimes', 'integer'], 'filter.active' => ['sometimes', 'in:true,false,1,0'], 'filter.search' => ['sometimes', 'string', 'max:255']];
    }
}
