<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PhysicalCount;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPhysicalCountRequest extends FormRequest
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
            'filter' => ['sometimes', 'array:warehouse_id,status'],
            'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', Rule::enum(PhysicalCountStatus::class)],
        ], $this->listQueryRules(['created_at', 'posted_at'], ['warehouse', 'lines']));
    }
}
