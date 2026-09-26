<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\ItemQuantityUnit;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexItemQuantityUnitRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:item_id'],
            'filter.item_id' => ['sometimes', 'integer', 'min:1'],
        ], $this->listQueryRules(['created_at'], ['item', 'uom']));
    }
}
