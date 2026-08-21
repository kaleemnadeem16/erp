<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Item;

use App\Modules\Inventory\Support\ItemType;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'array:category_id,type,status,search'],
            'filter.category_id' => ['sometimes', 'integer', 'min:1'],
            'filter.type' => ['sometimes', Rule::enum(ItemType::class)],
            'filter.status' => ['sometimes', Rule::enum(Status::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
