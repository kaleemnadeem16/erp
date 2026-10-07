<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PackageStock;

use Illuminate\Foundation\Http\FormRequest;

class SuggestPackageDrawRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.package-state.view') === true && $this->user()->can('inventory.package-state.issue') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['item_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'], 'batch_id' => ['sometimes', 'nullable', 'integer'], 'quantity' => ['required', 'decimal:0,4', 'gt:0']];
    }
}
