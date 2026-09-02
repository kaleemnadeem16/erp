<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\WarehouseLocationAssignment;

use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Modules\Inventory\Support\WarehouseLocationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseLocationAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var WarehouseLocationAssignment|null $assignment */
        $assignment = $this->route('warehouseLocationRole');

        return [
            'location_id' => ['sometimes', 'integer', Rule::exists('locations', 'id')->where(fn ($q) => $q->where('warehouse_id', $assignment?->warehouse_id))],
            'role' => ['sometimes', Rule::enum(WarehouseLocationRole::class), Rule::unique('warehouse_location_roles')->ignore($assignment?->id)->where(fn ($q) => $q->where('warehouse_id', $assignment?->warehouse_id))],
        ];
    }
}
