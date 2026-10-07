<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;

class ApproveInventoryCutoverNegativeOpeningRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.cutover.negative_opening.approve') === true
            && WarehouseScope::unrestricted($this->user(), 'inventory.cutover.negative_opening.approve');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
