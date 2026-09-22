<?php

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

use App\Http\Requests\Concerns\ValidatesIncludes;
use Illuminate\Foundation\Http\FormRequest;

class ShowPurchaseOrderRequest extends FormRequest
{
    use ValidatesIncludes;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->includeRules(['lines', 'supplierSite']);
    }
}
