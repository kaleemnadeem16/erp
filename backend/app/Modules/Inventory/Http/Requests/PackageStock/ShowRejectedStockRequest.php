<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PackageStock;

use Illuminate\Foundation\Http\FormRequest;

/** What is rejected and still on hand for a delivery line, and what exiting some of it would involve (ADR 0097). Read-only. */
class ShowRejectedStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyPermission(['purchasing.supplier-return.create', 'purchasing.supplier-return.post', 'inventory.scrap.create', 'inventory.scrap.post']) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:SUPPLIER_RETURN,SCRAP'],
            'quantity' => ['nullable', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999'],
        ];
    }
}
