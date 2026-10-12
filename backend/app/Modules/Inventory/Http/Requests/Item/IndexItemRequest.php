<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Item;

use App\Http\Requests\Concerns\ValidatesListQuery;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\PackageTrackingMode;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Support\PurchasingReadAccess;
use App\Support\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexItemRequest extends FormRequest
{
    use ValidatesListQuery;

    public function authorize(): bool
    {
        // Which items a supplier supplies is Purchasing's sourcing data, read only by those who may read item-supplier links: filtering by it would
        // otherwise let anyone who can list items probe those links (a filter is a read).
        if ($this->has('filter.supplier_id') || $this->has('filter.has_supplier')) {
            return PurchasingReadAccess::itemSuppliers($this->user());
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'filter' => ['sometimes', 'array:category_id,type,status,search,is_stocked,is_purchasable,is_sellable,valuation_method,tracking,supplier_id,has_supplier,has_packaging,stock_uom_id,package_tracking_mode'],
            'filter.category_id' => ['sometimes', 'integer', 'min:1'],
            'filter.type' => ['sometimes', Rule::enum(ItemType::class)],
            'filter.status' => ['sometimes', Rule::enum(Status::class)],
            'filter.search' => ['sometimes', 'string', 'max:255'],
            'filter.is_stocked' => ['sometimes', 'boolean'],
            'filter.is_purchasable' => ['sometimes', 'boolean'],
            'filter.is_sellable' => ['sometimes', 'boolean'],
            'filter.valuation_method' => ['sometimes', Rule::enum(ValuationMethod::class)],
            'filter.tracking' => ['sometimes', Rule::enum(ItemTracking::class)],
            'filter.supplier_id' => ['sometimes', 'integer', 'min:1'],
            'filter.has_supplier' => ['sometimes', 'boolean'],
            'filter.has_packaging' => ['sometimes', 'boolean'],
            'filter.stock_uom_id' => ['sometimes', 'integer', 'min:1'],
            'filter.package_tracking_mode' => ['sometimes', Rule::enum(PackageTrackingMode::class)],
        ], $this->listQueryRules(['code', 'name', 'created_at'], ['category']));
    }
}
