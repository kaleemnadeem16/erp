<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class InventorySettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;

        return [
            'inventory_location_mode' => $company->inventory_location_mode->value,
            'default_avco_pool_scope' => $company->default_avco_pool_scope->value,
            'negative_stock_mode' => $company->negative_stock_mode->value,
            'negative_stock_authorisation_mode' => $company->negative_stock_authorisation_mode->value,
            'negative_stock_review_sla_hours' => $company->negative_stock_review_sla_hours,
            'negative_stock_allow_self_review' => $company->negative_stock_allow_self_review,
            'negative_stock_sales_despatch_enabled' => $company->negative_stock_sales_despatch_enabled,
            'negative_stock_material_consumption_enabled' => $company->negative_stock_material_consumption_enabled,
            'negative_stock_supplier_return_enabled' => $company->negative_stock_supplier_return_enabled,
            'negative_stock_scrap_writeoff_enabled' => $company->negative_stock_scrap_writeoff_enabled,
            'backdating_horizon_mode' => $company->backdating_horizon_mode->value,
            'backdating_posting_floor_subtype' => $company->backdating_posting_floor_subtype?->value,
            'backdating_posting_floor_fixed_date' => $company->backdating_posting_floor_fixed_date?->toDateString(),
            'backdating_posting_floor_rolling_days' => $company->backdating_posting_floor_rolling_days,
            'authorised_forward_recalculation_enabled' => $company->authorised_forward_recalculation_enabled,
            'recalculation_review_sla_hours' => $company->recalculation_review_sla_hours,
            'recalculation_approval_ttl_hours' => $company->recalculation_approval_ttl_hours,
            'recalculation_high_risk_threshold_base_amount' => $this->when(
                $request->user()?->can('inventory.recalculation-policy.manage') || $request->user()?->can('inventory.policy-history.view'),
                $company->recalculation_high_risk_threshold_base_amount,
            ),
        ];
    }
}
