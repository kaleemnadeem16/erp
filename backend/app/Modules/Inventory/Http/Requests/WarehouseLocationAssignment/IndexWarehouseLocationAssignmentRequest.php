<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\WarehouseLocationAssignment;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\WarehouseLocationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexWarehouseLocationAssignmentRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:warehouse_id,location_id,role'],
            'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'filter.location_id' => ['sometimes', 'integer', 'min:1'],
            'filter.role' => ['sometimes', Rule::enum(WarehouseLocationRole::class)],
        ], $this->listQueryRules(['role', 'created_at'], ['warehouse', 'location']));
    }
}
