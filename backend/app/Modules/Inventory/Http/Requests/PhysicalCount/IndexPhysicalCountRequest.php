<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PhysicalCount;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\PhysicalCountReadAccess;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPhysicalCountRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        return PhysicalCountReadAccess::allows($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:warehouse_id,status,search'],
            'filter.warehouse_id' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', Rule::enum(PhysicalCountStatus::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
        ], $this->listQueryRules(['created_at', 'posted_at'], ['warehouse', 'lines']));
    }
}
