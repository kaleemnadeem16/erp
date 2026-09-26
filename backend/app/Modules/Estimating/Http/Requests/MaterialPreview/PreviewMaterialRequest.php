<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialPreview;

use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\RateDimension;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.material_preview.run') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Assembly $assembly */
        $assembly = $this->route('assembly');

        return [
            'assembly_version_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('assembly_versions', 'id')->where(
                    fn ($query) => $query->where('assembly_id', $assembly->id)
                ),
            ],
            'work_quantity' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'work_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'customer_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('customer_accounts')],
            'dimension_value_ids' => ['sometimes', 'array'],
            'dimension_value_ids.*' => [
                'integer', 'distinct',
                Rule::exists('rate_dimension_values', 'id')->where(
                    fn ($query) => $query->whereIn('rate_dimension_id', RateDimension::query()->select('id'))
                ),
            ],
        ];
    }
}
