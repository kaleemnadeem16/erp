<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\SupplierReturn;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.supplier-return.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'carrier_note' => ['nullable', 'string', 'max:255'],
            'expected_credit_amount' => ['nullable', 'decimal:0,4', 'min:0', 'max:999999999999999.9999'],
            'expected_credit_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'expected_credit_reference' => ['nullable', 'string', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.goods_receipt_line_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999'],
            'lines.*.outcome_change_reason' => ['nullable', 'string', 'max:1000'],
            'lines.*.package_portion' => ['nullable', 'array', 'max:50'],
            'lines.*.package_portion.*.kind' => ['required', 'in:SEALED,LOOSE'],
            'lines.*.package_portion.*.packaging_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.package_portion.*.count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'lines.*.package_portion.*.quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
        ];
    }
}
