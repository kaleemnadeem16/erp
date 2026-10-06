<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PhysicalCount;

use Illuminate\Foundation\Http\FormRequest;

class CancelPhysicalCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.physical_count.create') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
