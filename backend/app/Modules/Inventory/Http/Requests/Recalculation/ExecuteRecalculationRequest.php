<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Recalculation;

use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;

class ExecuteRecalculationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.recalculation.execute') === true
            && WarehouseScope::unrestricted($this->user(), 'inventory.recalculation.execute');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
