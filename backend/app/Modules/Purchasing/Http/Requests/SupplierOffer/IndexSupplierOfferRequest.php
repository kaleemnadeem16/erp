<?php

namespace App\Modules\Purchasing\Http\Requests\SupplierOffer;

use Illuminate\Foundation\Http\FormRequest;

class IndexSupplierOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.item-supplier.view') || $this->user()?->can('purchasing.item-supplier.manage') || $this->user()?->can('purchasing.purchase-order.create');
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1'], 'filter.item_id' => ['sometimes', 'integer'], 'filter.supplier_account_id' => ['sometimes', 'integer'], 'filter.active' => ['sometimes', 'in:true,false,1,0'], 'filter.search' => ['sometimes', 'string', 'max:255']];
    }
}
