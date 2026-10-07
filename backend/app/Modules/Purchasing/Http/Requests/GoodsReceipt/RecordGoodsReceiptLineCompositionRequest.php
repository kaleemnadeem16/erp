<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\GoodsReceipt;

use Illuminate\Foundation\Http\FormRequest;

/** The observed physical arrival of one receipt line: sealed packages of a definition plus an open/loose quantity (ADR 0082). */
class RecordGoodsReceiptLineCompositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.goods-receipt.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'composition' => ['required', 'array', 'min:1'],
            'composition.*.packaging_id' => ['nullable', 'integer'],
            'composition.*.count' => ['nullable', 'integer', 'min:1'],
            'composition.*.quantity' => ['nullable', 'decimal:0,4'],
        ];
    }
}
