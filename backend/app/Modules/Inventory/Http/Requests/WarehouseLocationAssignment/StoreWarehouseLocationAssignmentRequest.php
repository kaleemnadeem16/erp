<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\WarehouseLocationAssignment;

use App\Modules\Inventory\Support\WarehouseLocationRole;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWarehouseLocationAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.location.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', CompanyScoped::exists('warehouses')],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where(fn ($q) => $q->where('warehouse_id', $this->input('warehouse_id')))],
            'role' => ['required', Rule::enum(WarehouseLocationRole::class), Rule::unique('warehouse_location_roles')->where(fn ($q) => $q->where('warehouse_id', $this->input('warehouse_id')))],
        ];
    }
}
