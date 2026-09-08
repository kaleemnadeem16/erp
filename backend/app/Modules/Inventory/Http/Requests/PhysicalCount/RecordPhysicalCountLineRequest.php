<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PhysicalCount;

use Illuminate\Foundation\Http\FormRequest;

class RecordPhysicalCountLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'counted_qty' => ['required', 'decimal:0,4', 'min:0', 'max:999999999999999.9999'],
        ];
    }
}
