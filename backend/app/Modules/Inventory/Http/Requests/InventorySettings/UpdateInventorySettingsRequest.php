<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventorySettings;

use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\BackdatingHorizonMode;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\NegativeStockMode;
use App\Modules\Inventory\Support\PostingFloorSubtype;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventorySettingsRequest extends FormRequest
{
    private const LOCATION_STRUCTURE_FIELDS = ['inventory_location_mode'];

    private const VALUATION_POLICY_FIELDS = ['default_avco_pool_scope'];

    private const NEGATIVE_STOCK_FIELDS = [
        'negative_stock_mode', 'negative_stock_authorisation_mode', 'negative_stock_review_sla_hours',
        'negative_stock_allow_self_review', 'negative_stock_sales_despatch_enabled',
        'negative_stock_material_consumption_enabled', 'negative_stock_supplier_return_enabled',
        'negative_stock_scrap_writeoff_enabled',
    ];

    private const BACKDATING_HORIZON_FIELDS = [
        'accounting_timezone', 'backdating_horizon_mode', 'backdating_posting_floor_subtype',
        'backdating_posting_floor_fixed_date', 'backdating_posting_floor_rolling_days',
    ];

    private const RECALCULATION_FIELDS = [
        'authorised_forward_recalculation_enabled', 'recalculation_review_sla_hours', 'recalculation_approval_ttl_hours',
        'recalculation_high_risk_threshold_base_amount',
    ];

    public function authorize(): bool
    {
        if ($this->hasAny(self::LOCATION_STRUCTURE_FIELDS) && $this->user()?->can('inventory.location-policy.manage') !== true) {
            return false;
        }

        if ($this->hasAny(self::VALUATION_POLICY_FIELDS) && $this->user()?->can('inventory.valuation-policy.manage') !== true) {
            return false;
        }

        if ($this->hasAny(self::NEGATIVE_STOCK_FIELDS) && $this->user()?->can('inventory.negative-stock-policy.manage') !== true) {
            return false;
        }

        if ($this->hasAny(self::BACKDATING_HORIZON_FIELDS) && $this->user()?->can('inventory.backdating-horizon-policy.manage') !== true) {
            return false;
        }

        if ($this->hasAny(self::RECALCULATION_FIELDS) && $this->user()?->can('inventory.recalculation-policy.manage') !== true) {
            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'inventory_location_mode' => ['sometimes', Rule::enum(InventoryLocationMode::class)],
            'default_avco_pool_scope' => ['sometimes', Rule::enum(AvcoPoolScope::class)],
            'negative_stock_mode' => ['sometimes', Rule::enum(NegativeStockMode::class)],
            'negative_stock_authorisation_mode' => ['sometimes', Rule::enum(NegativeStockAuthorisationMode::class)],
            'negative_stock_review_sla_hours' => ['sometimes', 'integer', 'between:1,168'],
            'negative_stock_allow_self_review' => ['sometimes', 'boolean'],
            'negative_stock_sales_despatch_enabled' => ['sometimes', 'boolean'],
            'negative_stock_material_consumption_enabled' => ['sometimes', 'boolean'],
            'negative_stock_supplier_return_enabled' => ['sometimes', 'boolean'],
            'negative_stock_scrap_writeoff_enabled' => ['sometimes', 'boolean'],
            'accounting_timezone' => ['sometimes', 'timezone'],
            'backdating_horizon_mode' => ['sometimes', Rule::enum(BackdatingHorizonMode::class)],
            'backdating_posting_floor_subtype' => [
                'required_if:backdating_horizon_mode,'.BackdatingHorizonMode::PostingFloor->value,
                'prohibited_unless:backdating_horizon_mode,'.BackdatingHorizonMode::PostingFloor->value,
                Rule::enum(PostingFloorSubtype::class),
            ],
            'backdating_posting_floor_fixed_date' => [
                'required_if:backdating_posting_floor_subtype,'.PostingFloorSubtype::FixedDate->value,
                'prohibited_unless:backdating_posting_floor_subtype,'.PostingFloorSubtype::FixedDate->value,
                'date',
            ],
            'backdating_posting_floor_rolling_days' => [
                'required_if:backdating_posting_floor_subtype,'.PostingFloorSubtype::RollingFormula->value,
                'prohibited_unless:backdating_posting_floor_subtype,'.PostingFloorSubtype::RollingFormula->value,
                'integer', 'between:1,3650',
            ],
            'authorised_forward_recalculation_enabled' => ['sometimes', 'boolean'],
            'recalculation_review_sla_hours' => ['sometimes', 'integer', 'between:1,168'],
            'recalculation_approval_ttl_hours' => ['sometimes', 'integer', 'between:1,168'],
            'recalculation_high_risk_threshold_base_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'reason' => [
                'required_with:'.implode(',', [
                    ...self::LOCATION_STRUCTURE_FIELDS, ...self::VALUATION_POLICY_FIELDS,
                    ...self::NEGATIVE_STOCK_FIELDS, ...self::BACKDATING_HORIZON_FIELDS, ...self::RECALCULATION_FIELDS,
                ]),
                'string', 'max:1000',
            ],
        ];
    }
}
