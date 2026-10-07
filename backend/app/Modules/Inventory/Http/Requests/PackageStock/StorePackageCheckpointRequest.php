<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PackageStock;

use Illuminate\Foundation\Http\FormRequest;

/** An opening or recount observation for one item, location and batch: what is physically there, as sealed packages and loose quantity. */
class StorePackageCheckpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.physical_count.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'], 'batch_id' => ['sometimes', 'nullable', 'integer'],
            'request_key' => ['required', 'string', 'max:100'], 'reason' => ['required', 'string', 'max:1000'],
            'expected_quantity' => ['required', 'decimal:0,4', 'gt:0'],
            'composition' => ['required', 'array', 'min:1'],
            'composition.*.packaging_id' => ['nullable', 'integer'], 'composition.*.count' => ['nullable', 'integer', 'min:1'], 'composition.*.quantity' => ['nullable', 'decimal:0,4', 'gt:0'],
        ];
    }
}
