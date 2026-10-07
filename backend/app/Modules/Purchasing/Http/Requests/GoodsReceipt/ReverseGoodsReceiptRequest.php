<?php

namespace App\Modules\Purchasing\Http\Requests\GoodsReceipt;

use Illuminate\Foundation\Http\FormRequest;

class ReverseGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.goods-receipt.reverse') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            // Grouped items only: per receipt line, the whole sealed packages and loose quantity that are physically going back.
            'package_draws' => ['sometimes', 'array', 'max:200'],
            'package_draws.*.goods_receipt_line_id' => ['required', 'integer', 'min:1'],
            'package_draws.*.lines' => ['required', 'array', 'min:1', 'max:50'],
            'package_draws.*.lines.*.kind' => ['required', 'in:SEALED,LOOSE'],
            'package_draws.*.lines.*.packaging_id' => ['nullable', 'integer', 'min:1'],
            'package_draws.*.lines.*.count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'package_draws.*.lines.*.quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
        ];
    }
}
