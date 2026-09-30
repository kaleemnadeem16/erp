<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Http\Requests\MaterialPreview;

use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\RateDimension;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Inventory\Models\Warehouse;
use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estimating.material_preview.run') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Assembly $assembly */
        $assembly = $this->route('assembly');
        // Scope and strategy only steer the inventory proposal, which needs its own capability
        // (ADR 0057). Rejecting them outright, rather than ignoring them, means a caller without
        // it never gets a silently different question answered.
        $inventoryInput = $this->user()?->can('estimating.inventory_availability.view') === true ? 'sometimes' : 'prohibited';

        return [
            'assembly_version_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('assembly_versions', 'id')->where(
                    fn ($query) => $query->where('assembly_id', $assembly->id)
                ),
            ],
            'work_quantity' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'work_uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'customer_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('customer_accounts')],
            'dimension_value_ids' => ['sometimes', 'array'],
            'dimension_value_ids.*' => [
                'integer', 'distinct',
                Rule::exists('rate_dimension_values', 'id')->where(
                    fn ($query) => $query->whereIn('rate_dimension_id', RateDimension::query()->select('id'))
                ),
            ],
            // Hypothetical what-if quantities (ADR 0058): judged against the standard, never saved. Which
            // items the assembly actually requires is only known after resolution, so that check lives
            // in PreviewMaterialVariance.
            'planned_adjustments' => ['sometimes', 'array', 'max:100'],
            'planned_adjustments.*.item_id' => ['required', 'integer', 'distinct', CompanyScoped::exists('items')],
            'planned_adjustments.*.planned_quantity' => ['required', 'decimal:0,6', 'gte:0', 'max:999999999999.999999'],
            'planned_adjustments.*.uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
            'planned_adjustments.*.reason_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('material_variance_reasons')],
            'planned_adjustments.*.note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Narrows, never widens, the stock the proposal may consider (ADR 0058).
            'inventory_scope' => [$inventoryInput, 'array:warehouse_ids,location_ids'],
            'inventory_scope.warehouse_ids' => ['sometimes', 'array', 'max:100'],
            'inventory_scope.warehouse_ids.*' => ['integer', 'distinct', CompanyScoped::exists('warehouses')],
            'inventory_scope.location_ids' => ['sometimes', 'array', 'max:100'],
            'inventory_scope.location_ids.*' => [
                'integer', 'distinct',
                Rule::exists('locations', 'id')->where(
                    fn ($query) => $query->whereIn('warehouse_id', Warehouse::query()->select('id'))
                ),
            ],
            'selection_strategy' => [$inventoryInput, 'nullable', Rule::enum(MaterialSelectionStrategy::class)],
            // A hand-built draw (ADR 0072): the quantity to take from each location/batch, in any unit that
            // converts to the item's stock unit. Validated against the real usable balances in PriceStockMix.
            'stock_mix' => [$inventoryInput, 'array', 'max:200'],
            'stock_mix.*.item_id' => ['required', 'integer', CompanyScoped::exists('items')],
            'stock_mix.*.location_id' => [
                'required', 'integer',
                Rule::exists('locations', 'id')->where(
                    fn ($query) => $query->whereIn('warehouse_id', Warehouse::query()->select('id'))
                ),
            ],
            'stock_mix.*.batch_id' => ['sometimes', 'nullable', 'integer', CompanyScoped::exists('stock_batches')],
            'stock_mix.*.quantity' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999999.999999'],
            'stock_mix.*.uom_id' => ['required', 'integer', CompanyScoped::exists('uoms')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'inventory_scope.prohibited' => 'You do not have permission to narrow inventory availability.',
            'stock_mix.prohibited' => 'You do not have permission to choose stock for a material plan.',
            'selection_strategy.prohibited' => 'You do not have permission to choose a stock selection strategy.',
        ];
    }
}
