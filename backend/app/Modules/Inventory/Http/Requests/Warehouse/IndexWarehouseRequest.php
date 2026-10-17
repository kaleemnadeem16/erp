<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Warehouse;

use App\Http\Requests\Concerns\ValidatesListQuery;
use Illuminate\Foundation\Http\FormRequest;

class IndexWarehouseRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(
            [
                'filter' => ['sometimes', 'array:active,is_default,has_locations,search'],
                'filter.active' => ['sometimes', 'boolean'],
                'filter.is_default' => ['sometimes', 'boolean'],
                'filter.has_locations' => ['sometimes', 'boolean'],
                'filter.search' => ['sometimes', 'string', 'max:255'],
            ],
            $this->listQueryRules(
                ['code', 'name', 'is_default', 'active', 'created_at', 'updated_at'],
                ['locations']
            )
        );
    }
}
