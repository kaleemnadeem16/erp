<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Warehouse;

use App\Modules\Inventory\Models\Warehouse;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.warehouse.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => $this->string('code')->lower()->toString()]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    { /** @var Warehouse|null $warehouse */ $warehouse = $this->route('warehouse');

        return ['code' => ['sometimes', 'string', 'max:255', CompanyScoped::unique('warehouses', 'code', $warehouse?->id)], 'name' => ['sometimes', 'string', 'max:255'], 'is_default' => ['sometimes', 'boolean'], 'active' => ['sometimes', 'boolean']];
    }
}
