<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\Assembly;

use App\Modules\Estimating\Support\MarginType;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssemblyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // status is deliberately absent — every assembly starts DRAFT and
            // has no activation mechanism until Step 3 adds versions.
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('assemblies', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'work_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'assembly_group_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('assembly_groups')],
            'margin_type' => ['required', Rule::enum(MarginType::class)],
            'margin_pct' => ['required', 'decimal:0,4', 'gte:0'],
        ];
    }
}
