<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\AssemblyGroup;

use App\Modules\Estimating\Models\AssemblyGroup;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssemblyGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.assembly-group.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var AssemblyGroup|null $assemblyGroup */
        $assemblyGroup = $this->route('assembly_group');

        return [
            'code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('assembly_groups', 'code', $assemblyGroup?->id)],
            'name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
