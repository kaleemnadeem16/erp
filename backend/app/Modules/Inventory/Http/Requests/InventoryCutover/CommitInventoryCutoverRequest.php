<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;

class CommitInventoryCutoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.cutover.commit') === true
            && WarehouseScope::unrestricted($this->user(), 'inventory.cutover.commit');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
