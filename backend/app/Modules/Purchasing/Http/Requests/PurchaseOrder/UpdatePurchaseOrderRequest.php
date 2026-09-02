<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

class UpdatePurchaseOrderRequest extends StorePurchaseOrderRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ($rules as $field => &$rule) {
            if (! str_starts_with($field, 'lines.*.')) {
                $rule = array_values(array_filter($rule, fn ($value) => $value !== 'required'));
                array_unshift($rule, 'sometimes');
            }
        }
        unset($rule);
        $rules['lines'] = ['sometimes', 'array', 'min:1'];

        return $rules;
    }
}
