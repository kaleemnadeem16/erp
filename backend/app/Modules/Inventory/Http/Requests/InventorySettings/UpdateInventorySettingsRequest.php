<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventorySettings;

use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\NegativeStockMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInventorySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policyFields = [
            'negative_stock_mode', 'negative_stock_authorisation_mode', 'negative_stock_review_sla_hours',
            'negative_stock_allow_self_review', 'negative_stock_sales_despatch_enabled',
            'negative_stock_material_consumption_enabled', 'negative_stock_supplier_return_enabled',
            'negative_stock_scrap_writeoff_enabled',
        ];

        return (! $this->hasAny($policyFields)) || $this->user()?->can('inventory.negative-stock-policy.manage') === true;
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
            'reason' => ['required_with:negative_stock_mode,negative_stock_authorisation_mode,negative_stock_review_sla_hours,negative_stock_allow_self_review,negative_stock_sales_despatch_enabled,negative_stock_material_consumption_enabled,negative_stock_supplier_return_enabled,negative_stock_scrap_writeoff_enabled', 'string', 'max:1000'],
        ];
    }
}
