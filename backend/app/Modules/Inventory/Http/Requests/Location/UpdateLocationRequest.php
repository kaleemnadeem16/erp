<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Location;

use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\LocationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.location.manage') === true && ($this->missing('counts_as_usable') || $this->user()->can('inventory.location-policy.manage'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => $this->string('code')->lower()->toString()]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {/** @var Location|null $location */ $location = $this->route('location');

        return [
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('locations', 'id')->where(fn ($q) => $q->where('warehouse_id', $location?->warehouse_id))],
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('locations')->ignore($location?->id)->where(fn ($q) => $q->where('warehouse_id', $location?->warehouse_id))],
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(LocationType::class)],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('locations')->ignore($location?->id)->where(fn ($q) => $q->where('warehouse_id', $location?->warehouse_id))],
            'sort_order' => ['sometimes', 'integer', 'between:0,32767'],
            'allows_stock' => ['sometimes', 'boolean'],
            // null clears the mark (follow the type again). Changing what counts as usable changes what
            // planners see, so it is a governed policy change: it needs the policy permission and a reason.
            'counts_as_usable' => ['sometimes', 'nullable', 'boolean'],
            'counts_as_usable_reason' => ['required_with:counts_as_usable', 'nullable', 'string', 'max:1000'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
