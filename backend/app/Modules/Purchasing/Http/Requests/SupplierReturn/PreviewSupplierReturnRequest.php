<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\SupplierReturn;

use Illuminate\Foundation\Http\FormRequest;

/** Read-only preview of what posting the given lines would do (ADR 0097); posts and saves nothing. */
class PreviewSupplierReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyPermission(['purchasing.supplier-return.create', 'purchasing.supplier-return.post']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.goods_receipt_line_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['nullable', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999'],
            'lines.*.outcome_change_reason' => ['nullable', 'string', 'max:1000'],
            'lines.*.package_portion' => ['nullable', 'array', 'max:50'],
            'lines.*.package_portion.*.kind' => ['required', 'in:SEALED,LOOSE'],
            'lines.*.package_portion.*.packaging_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.package_portion.*.count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'lines.*.package_portion.*.quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
        ];
    }
}
