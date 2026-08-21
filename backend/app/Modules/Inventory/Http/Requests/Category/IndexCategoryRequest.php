<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;

class IndexCategoryRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:parent_id,search'],
            // 0 is a legitimate request: "top-level categories only" (parent_id is null).
            // A plain integer|min:1 filter couldn't express that, so it's its own case.
            'filter.parent_id' => ['sometimes', 'integer', 'min:0'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
