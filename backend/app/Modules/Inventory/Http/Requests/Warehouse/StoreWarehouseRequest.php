<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Warehouse;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => $this->string('code')->lower()->toString()]);
        } if ($this->filled('location_code')) {
            $this->merge(['location_code' => $this->string('location_code')->lower()->toString()]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:255', CompanyScoped::unique('warehouses', 'code')], 'name' => ['required', 'string', 'max:255'], 'is_default' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean'], 'location_code' => ['required', 'string', 'max:255'], 'location_name' => ['required', 'string', 'max:255']];
    }
}
