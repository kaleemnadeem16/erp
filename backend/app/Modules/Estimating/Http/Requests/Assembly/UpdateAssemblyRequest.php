<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\Assembly;

use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Support\MarginType;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssemblyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.assembly.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Assembly|null $assembly */
        $assembly = $this->route('assembly');

        return [
            // status is deliberately absent — see ArchiveAssembly for the only
            // supported status transition until Step 3 adds versions.
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('assemblies', 'code', $assembly?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'work_uom_id' => ['sometimes', 'integer', CompanyScoped::exists('uoms')],
            'assembly_group_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('assembly_groups')],
            'margin_type' => ['sometimes', Rule::enum(MarginType::class)],
            'margin_pct' => ['sometimes', 'decimal:0,4', 'gte:0'],
        ];
    }
}
