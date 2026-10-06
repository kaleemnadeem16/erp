<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\GoodsReceipt;

class UpdateGoodsReceiptRequest extends StoreGoodsReceiptRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge(parent::rules(), ['lines.*.id' => ['sometimes', 'integer', 'min:1', 'distinct']]);
    }
}
