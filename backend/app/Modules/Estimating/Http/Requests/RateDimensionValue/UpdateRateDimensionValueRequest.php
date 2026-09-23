<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\RateDimensionValue;

use App\Modules\Estimating\Models\RateDimensionValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRateDimensionValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var RateDimensionValue|null $rateDimensionValue */
        $rateDimensionValue = $this->route('rate_dimension_value');

        return [
            'code' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('rate_dimension_values', 'code')
                    ->where(fn ($query) => $query->where('rate_dimension_id', $rateDimensionValue?->rate_dimension_id))
                    ->ignore($rateDimensionValue?->id),
            ],
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
