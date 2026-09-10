<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryOverview;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexInventoryOverviewRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:category_id,search'],
            'filter.category_id' => ['sometimes', 'integer', 'min:1'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['code', 'name'], ['category']));
    }
}
