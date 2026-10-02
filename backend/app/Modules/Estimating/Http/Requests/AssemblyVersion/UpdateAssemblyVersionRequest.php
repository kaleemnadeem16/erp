<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\AssemblyVersion;

use App\Modules\Estimating\Http\Requests\Concerns\ValidatesAssemblyComponents;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssemblyVersionRequest extends FormRequest
{
    use ValidatesAssemblyComponents;

    public function authorize(): bool
    {
        return $this->user()?->can('estimating.assembly.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // status/effective_from/effective_to are deliberately absent — only
        // ActivateAssemblyVersion may change them.
        return array_merge([
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], $this->componentFieldRules());
    }
}
