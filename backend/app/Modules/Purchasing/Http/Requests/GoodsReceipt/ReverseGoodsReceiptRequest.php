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
        return ['reason' => ['required', 'string', 'max:1000']];
    }
}
