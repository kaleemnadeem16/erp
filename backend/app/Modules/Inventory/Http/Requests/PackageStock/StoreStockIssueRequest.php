<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PackageStock;

use Illuminate\Foundation\Http\FormRequest;

/** A grouped (package-tracked) stock issue: what was physically taken, as sealed packages and loose quantity, opening a package where needed. */
class StoreStockIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.package-state.issue') === true && $this->user()->can('inventory.package-state.view') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'], 'batch_id' => ['sometimes', 'nullable', 'integer'],
            'request_key' => ['required', 'string', 'max:85'], 'reason' => ['required', 'string', 'max:500'], 'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'quantity' => ['sometimes', 'nullable', 'decimal:0,4', 'gt:0'],
            'draw' => ['required', 'array', 'min:1'],
            'draw.*.kind' => ['required', 'in:SEALED,LOOSE,UNPACK,OPEN_AND_DRAW'],
            'draw.*.packaging_id' => ['nullable', 'integer'], 'draw.*.count' => ['nullable', 'integer', 'min:1'], 'draw.*.quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
        ];
    }
}
