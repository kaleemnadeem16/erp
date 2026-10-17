<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryOverview;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\StockVisibility;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInventoryOverviewRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        // The overview adds company-wide purchase-order quantities and pool-level values to what is on hand, so it cannot be limited to a
        // warehouse without misleading: a person limited to their units gets 403, not a figure that includes other warehouses (ADR 0089).
        return StockVisibility::availability($this->user())
            && WarehouseScope::unrestricted($this->user(), [StockVisibility::AVAILABILITY, StockVisibility::STOCK]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:category_id,search,stock_status,type,valuation_method,tracking'],
            'filter.category_id' => ['sometimes', 'integer', 'min:1'],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.stock_status' => ['sometimes', 'string', Rule::in(['shortage', 'qa_held', 'on_order', 'in_stock', 'out_of_stock', 'available'])],
            'filter.type' => ['sometimes', 'string', Rule::in(array_map(fn (ItemType $case): string => $case->value, ItemType::cases()))],
            'filter.valuation_method' => ['sometimes', 'string', Rule::in(array_map(fn (ValuationMethod $case): string => $case->value, ValuationMethod::cases()))],
            'filter.tracking' => ['sometimes', 'string', Rule::in(array_map(fn (ItemTracking $case): string => $case->value, ItemTracking::cases()))],
        ], $this->listQueryRules(['code', 'name'], ['category']));
    }
}
