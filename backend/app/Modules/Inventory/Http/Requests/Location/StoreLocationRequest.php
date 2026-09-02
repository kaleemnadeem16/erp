<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Location;

use App\Modules\Inventory\Support\LocationType;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
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
    {
        return [
            'warehouse_id' => ['required', 'integer', CompanyScoped::exists('warehouses')],
            'parent_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where(fn ($q) => $q->where('warehouse_id', $this->input('warehouse_id')))],
            'code' => ['required', 'string', 'max:255', Rule::unique('locations')->where(fn ($q) => $q->where('warehouse_id', $this->input('warehouse_id')))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(LocationType::class)],
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('locations')->where(fn ($q) => $q->where('warehouse_id', $this->input('warehouse_id')))],
            'sort_order' => ['sometimes', 'integer', 'between:0,32767'],
            'allows_stock' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
