<?php

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

use App\Http\Requests\Concerns\ValidatesIncludes;
use App\Modules\Purchasing\Support\PurchasingReadAccess;
use Illuminate\Foundation\Http\FormRequest;

class ShowPurchaseOrderRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return PurchasingReadAccess::purchaseOrders($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['lines', 'supplierSite']);
    }
}
