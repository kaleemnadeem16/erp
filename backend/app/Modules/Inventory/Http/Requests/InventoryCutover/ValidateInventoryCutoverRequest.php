<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use Illuminate\Foundation\Http\FormRequest;

class ValidateInventoryCutoverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.cutover.validate') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
