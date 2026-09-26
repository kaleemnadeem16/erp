<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\RateDimensionValue;

use Illuminate\Foundation\Http\FormRequest;

class IndexRateDimensionValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
