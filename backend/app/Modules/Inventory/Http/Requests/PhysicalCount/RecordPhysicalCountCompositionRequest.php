<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PhysicalCount;

use Illuminate\Foundation\Http\FormRequest;

/** Shape only; RecordPhysicalCountComposition enforces tracked levels, exact quantities and the draft state. An empty composition means nothing was found. */
class RecordPhysicalCountCompositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.physical_count.count') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'composition' => ['present', 'array', 'max:50'],
            'composition.*.packaging_id' => ['nullable', 'integer', 'min:1'],
            'composition.*.count' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'composition.*.quantity' => ['nullable', 'decimal:0,4', 'gt:0', 'max:999999999999999.9999'],
        ];
    }
}
