<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\ApplicationRate;

use App\Modules\Estimating\Models\RateDimension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // item_id/work_uom_id are deliberately absent — an application rate
            // cannot be repointed, see ApplicationRate::booted().
            'qty_per_work_unit' => ['sometimes', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'uom_id' => ['sometimes', 'integer'],
            'is_default' => ['sometimes', 'boolean'],
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
