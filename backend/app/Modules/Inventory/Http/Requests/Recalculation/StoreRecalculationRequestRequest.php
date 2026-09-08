<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Recalculation;

use App\Modules\Inventory\Support\RecalculationMovementKind;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only — RequestStockRecalculation itself enforces the domain rules a
 * plain rule set can't express: the RECEIPT-vs-outbound cost/currency
 * mutual exclusion, genuine-conflict and backdating-horizon checks,
 * tracking-shape validation. Duplicated here, this would drift.
 */
class StoreRecalculationRequestRequest extends FormRequest
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
            // locations has no direct company_id column — same precedent as
            // every prior Phase 2 FormRequest that references one.
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'movement_kind' => ['sometimes', Rule::enum(RecalculationMovementKind::class)],
            'destination_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'uom_id' => ['nullable', 'integer', CompanyScoped::exists('uoms')],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'serial_no' => ['nullable', 'string', 'max:255'],
            'qty' => ['required', 'decimal:0,4', 'gt:0'],
            'unit_cost' => ['nullable', 'decimal:0,4'],
            'base_unit_cost' => ['nullable', 'decimal:0,4'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'fx_rate' => ['nullable', 'decimal:0,9', 'gt:0'],
            'moved_at' => ['required', 'date'],
            'source_type' => ['required', 'string', 'max:255'],
            'source_id' => ['required', 'integer'],
        ];
    }
}
