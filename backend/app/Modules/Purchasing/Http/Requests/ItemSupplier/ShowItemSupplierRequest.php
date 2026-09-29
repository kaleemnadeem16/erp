<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\ItemSupplier;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Purchasing\Support\PurchasingReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class ShowItemSupplierRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return PurchasingReadAccess::itemSuppliers($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['item', 'supplierAccount', 'purchaseUom']);
    }
}
