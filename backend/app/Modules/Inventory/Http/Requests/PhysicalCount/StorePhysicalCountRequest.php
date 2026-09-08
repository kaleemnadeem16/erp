<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PhysicalCount;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class StorePhysicalCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', CompanyScoped::exists('warehouses')],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
