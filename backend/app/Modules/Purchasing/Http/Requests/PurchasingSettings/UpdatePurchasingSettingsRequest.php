<?php

namespace App\Modules\Purchasing\Http\Requests\PurchasingSettings;

use App\Modules\Purchasing\Support\OverReceiptMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchasingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['over_receipt_mode' => ['sometimes', Rule::enum(OverReceiptMode::class)], 'over_receipt_tolerance_percent' => ['sometimes', 'decimal:0,4', 'between:0,100'], 'purchase_order_required' => ['sometimes', 'boolean']];
    }
}
