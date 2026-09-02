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
        return true;
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
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
