<?php

namespace App\Modules\Purchasing\Http\Requests\PurchasingSettings;

use App\Modules\Purchasing\Support\OverReceiptMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchasingSettingsRequest extends FormRequest
{
    private const POLICY_FIELDS = ['over_receipt_mode', 'over_receipt_tolerance_percent', 'purchase_order_required'];

    public function authorize(): bool
    {
        return ! $this->hasAny(self::POLICY_FIELDS)
            || $this->user()?->can('purchasing.over-receipt-policy.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'over_receipt_mode' => ['sometimes', Rule::enum(OverReceiptMode::class)],
            'over_receipt_tolerance_percent' => ['sometimes', 'decimal:0,4', 'between:0,100'],
            'purchase_order_required' => ['sometimes', 'boolean'],
            'reason' => ['required_with:'.implode(',', self::POLICY_FIELDS), 'string', 'max:1000'],
        ];
    }
}
