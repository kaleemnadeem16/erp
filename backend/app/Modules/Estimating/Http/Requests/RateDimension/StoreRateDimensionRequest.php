<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\RateDimension;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class StoreRateDimensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('rate_dimensions', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_required' => ['sometimes', 'boolean'],
        ];
    }
}
