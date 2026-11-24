<?php

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

/** Who may be named buyer: asked by someone who drafts purchase orders, so nobody else learns the company's members from here (ADR 0192). */
class PurchaseOrderBuyerLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.purchase-order.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
