<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Location;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\LocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexLocationRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:warehouse_id,parent_id,type,active,search'],
            'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'filter.parent_id' => ['sometimes', 'integer', 'min:1'],
            'filter.type' => ['sometimes', Rule::enum(LocationType::class)],
            'filter.active' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['code', 'name', 'sort_order', 'created_at'], ['warehouse', 'parent', 'children']));
    }
}
