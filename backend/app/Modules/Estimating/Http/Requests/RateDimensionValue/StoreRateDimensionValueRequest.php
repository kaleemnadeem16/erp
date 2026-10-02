<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\RateDimensionValue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRateDimensionValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.rate-dimension.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rateDimension = $this->route('rate_dimension');

        return [
            'code' => [
                'required', 'string', 'max:255',
                Rule::unique('rate_dimension_values', 'code')
                    ->where(fn ($query) => $query->where('rate_dimension_id', $rateDimension?->id)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
