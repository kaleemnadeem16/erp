<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\AssemblyGroup;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssemblyGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.assembly-group.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('assembly_groups', 'code')],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
