<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\ItemQuantityUnit;

use Illuminate\Foundation\Http\FormRequest;

class UpdateItemQuantityUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // item_id/uom_id are deliberately absent — the pair identifies which
        // override this is; repointing it is delete-and-recreate, matching
        // ItemSupplier's item_id/supplier_account_id (also update-immutable).
        return [
            'factor' => ['sometimes', 'decimal:0,9', 'gt:0', 'max:9999999999.999999999'],
            'quantity_precision' => ['sometimes', 'nullable', 'decimal:0,9', 'gt:0', 'max:9999999999.999999999'],
        ];
    }
}
