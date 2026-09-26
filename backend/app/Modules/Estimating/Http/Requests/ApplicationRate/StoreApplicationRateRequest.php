<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\ApplicationRate;

use App\Modules\Estimating\Models\RateDimension;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationRateRequest extends FormRequest
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
            'qty_per_work_unit' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'is_default' => ['sometimes', 'boolean'],
            'dimension_value_ids' => ['sometimes', 'array'],
            // rate_dimension_values carries no company_id of its own — scoped through
            // its parent rate_dimensions, so CompanyScoped::exists() (which assumes a
            // company_id column) can't be used here directly.
            'dimension_value_ids.*' => [
                'integer',
                Rule::exists('rate_dimension_values', 'id')->where(
                    fn ($query) => $query->whereIn('rate_dimension_id', RateDimension::query()->select('id'))
                ),
            ],
        ];
    }
}
