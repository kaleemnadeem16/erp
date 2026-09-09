<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\NegativeStockOverride;

use App\Modules\Inventory\Support\NegativeStockMovementClass;
use App\Modules\Inventory\Support\NegativeStockReasonCode;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only — RequestNegativeStockOverride itself enforces the domain
 * rules a plain rule set can't express: company negative-stock mode/policy
 * eligibility, tracking/valuation exclusions, and the genuine-positive-
 * shortage check. Duplicated here, this would drift.
 */
class StoreNegativeStockOverrideRequestRequest extends FormRequest
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
            // StorePhysicalCountLineRequest and the cutover negative-opening
            // request, which rely on the Action's own resolution instead.
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'batch_id' => ['nullable', 'integer', CompanyScoped::exists('stock_batches')],
            'movement_class' => ['required', Rule::enum(NegativeStockMovementClass::class)],
            'requested_qty' => ['required', 'decimal:0,4', 'gt:0'],
            // available_qty is deliberately not accepted — the Action derives
            // it from live stock, because it decides how much shortage the
            // movement boundary will later authorise.
            'source_type' => ['required', 'string', 'max:255'],
            'source_id' => ['required', 'integer'],
            'reason_code' => ['required', Rule::enum(NegativeStockReasonCode::class)],
            'explanation' => ['required', 'string'],
        ];
    }
}
