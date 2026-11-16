<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

use App\Modules\Purchasing\Models\PurchaseOrder;

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

    /** A location sent on its own is judged against the warehouse the order already has. */
    protected function shipToWarehouseId(): mixed
    {
        if ($this->exists('ship_to_warehouse_id')) {
            return $this->input('ship_to_warehouse_id');
        }
        $order = $this->route('purchaseOrder') ?? $this->route('purchase_order');

        return $order instanceof PurchaseOrder ? $order->ship_to_warehouse_id : null;
    }
}
