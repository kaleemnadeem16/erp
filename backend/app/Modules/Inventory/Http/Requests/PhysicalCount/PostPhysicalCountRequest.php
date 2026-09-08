<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PhysicalCount;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `found_costs` is a list of {physical_count_line_id, unit_cost} pairs, not a
 * physical_count_line_id-keyed object — a dynamic-key map doesn't have a
 * sound OpenAPI/generated-client type, and the controller reshapes this list
 * into the id => cost array PostPhysicalCount::__invoke() actually expects.
 * Only positive-variance lines need an entry; PostPhysicalCount itself is
 * what rejects a missing one — this only validates the shape of whatever was
 * actually sent.
 */
class PostPhysicalCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'found_costs' => ['sometimes', 'array'],
            'found_costs.*.physical_count_line_id' => ['required', 'integer'],
            'found_costs.*.unit_cost' => ['required', 'decimal:0,4', 'gt:0'],
        ];
    }
}
