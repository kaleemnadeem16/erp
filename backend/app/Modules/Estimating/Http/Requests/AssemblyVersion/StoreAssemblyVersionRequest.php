<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\AssemblyVersion;

use App\Modules\Estimating\Http\Requests\Concerns\ValidatesAssemblyComponents;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssemblyVersionRequest extends FormRequest
{
    use ValidatesAssemblyComponents;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ], $this->componentFieldRules());
    }
}
