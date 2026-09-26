<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\ApplicationRate;

use App\Modules\Estimating\Models\RateDimension;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveApplicationRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'work_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'dimension_value_ids' => ['sometimes', 'array'],
            'dimension_value_ids.*' => [
                'integer',
                Rule::exists('rate_dimension_values', 'id')->where(
                    fn ($query) => $query->whereIn('rate_dimension_id', RateDimension::query()->select('id'))
                ),
            ],
        ];
    }
}
