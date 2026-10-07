<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PackageStock;

use Illuminate\Foundation\Http\FormRequest;

class ShowPackageBalancesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.package-state.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['item_id' => ['required', 'integer'], 'location_id' => ['sometimes', 'nullable', 'integer'], 'batch_id' => ['sometimes', 'nullable', 'integer']];
    }
}
