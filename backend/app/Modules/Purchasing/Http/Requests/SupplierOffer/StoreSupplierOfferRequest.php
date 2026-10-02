<?php

namespace App\Modules\Purchasing\Http\Requests\SupplierOffer;

use App\Modules\Purchasing\Actions\MaintainSupplierOffer;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.item-supplier.manage') === true;
    }

    public function rules(): array
    {
        return MaintainSupplierOffer::rules();
    }
}
