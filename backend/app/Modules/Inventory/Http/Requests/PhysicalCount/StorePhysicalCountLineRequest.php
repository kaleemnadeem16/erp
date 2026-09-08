<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\PhysicalCount;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only — AddPhysicalCountLine itself enforces the domain rules a plain
 * exists() can't express: the location must belong to the count's warehouse,
 * and batch/serial must match the item's tracking shape (see its own
 * validateTrackingShape()). Duplicated here, this would drift.
 */
class StorePhysicalCountLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'serial_no' => ['nullable', 'string', 'max:255'],
        ];
    }
}
