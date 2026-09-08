<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape only — RequestInventoryCutoverNegativeOpening itself enforces the
 * domain rules a plain rule set can't express: evidence must come from the
 * cutover's own negative source facts, tracking/method exclusions, and the
 * default-target-date-vs-reason rule. Duplicated here, this would drift.
 */
class RequestInventoryCutoverNegativeOpeningRequest extends FormRequest
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
            // locations has no direct company_id column (scoped through its
            // warehouse via BelongsToCompanyThroughParent) — mirrors
            // StorePhysicalCountLineRequest, which relies on the Action's
            // own resolution to enforce real scoping.
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'batch_no' => ['nullable', 'string', 'max:255'],
            'provisional_unit_cost' => ['required', 'decimal:0,4', 'gt:0'],
            'reason' => ['required', 'string'],
            'supporting_evidence' => ['required', 'array', 'min:1'],
            'responsible_owner_id' => ['required', 'integer', CompanyScoped::exists('users')],
            'resolution_plan' => ['required', 'string'],
            'target_date' => ['nullable', 'date'],
            'target_date_reason' => ['nullable', 'string', 'required_with:target_date'],
        ];
    }
}
