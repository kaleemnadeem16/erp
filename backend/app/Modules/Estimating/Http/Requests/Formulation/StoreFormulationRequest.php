<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\Formulation;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class StoreFormulationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'output_item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'code' => ['required', 'string', 'max:255', CompanyScoped::unique('formulations', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'output_qty' => ['sometimes', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'output_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'customer_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('customer_accounts')],
            'lines' => ['sometimes', 'array'],
            'lines.*.input_item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'lines.*.qty' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'lines.*.uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'lines.*.wastage_pct' => ['sometimes', 'decimal:0,4', 'gte:0'],
            'lines.*.sequence' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
