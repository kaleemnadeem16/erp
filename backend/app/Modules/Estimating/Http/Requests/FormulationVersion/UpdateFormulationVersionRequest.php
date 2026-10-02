<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\FormulationVersion;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFormulationVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.formulation.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // status/effective_from/effective_to are deliberately absent — only
            // ActivateFormulationVersion may change them.
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array'],
            'lines.*.input_item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'lines.*.qty' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'lines.*.uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'lines.*.wastage_pct' => ['sometimes', 'decimal:0,4', 'gte:0'],
            'lines.*.sequence' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
