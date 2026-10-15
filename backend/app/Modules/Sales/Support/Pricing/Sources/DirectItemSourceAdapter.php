<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Sources;

use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingQuantity;
use App\Modules\Sales\Support\Pricing\PricingRefusal;
use App\Modules\Sales\Support\Pricing\PricingSourceAdapter;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Pricing\ResolvedSource;

/**
 * Direct goods Item. Cost is read through the C1 `QueryMaterialCost` port for the priced quantity and mapped to certainty as-is: an unstocked or
 * unresolved item is UNKNOWN, a partial roll-up stays PARTIAL. The read reserves and posts nothing.
 */
final class DirectItemSourceAdapter implements PricingSourceAdapter
{
    public function __construct(private readonly QueryMaterialCost $cost) {}

    public function kind(): PricingSourceKind
    {
        return PricingSourceKind::DirectItem;
    }

    public function resolve(PricingInput $input): ResolvedSource
    {
        $item = ItemSourceLookup::sellable($input);
        if (in_array($item->type, [ItemType::Service, ItemType::Labour, ItemType::Plant], true)) {
            throw PricingRefusal::make(PricingRefusal::SOURCE_INCOMPATIBLE, 'This item is a service or resource; declare it as a service source or price it through an Assembly.', 'source');
        }
        $quantity = PricingQuantity::forItem($item, $input->quantity);
        $cost = $item->is_stocked
            ? FinancialResult::fromCost(($this->cost)(new MaterialCostRequest($item, $quantity)))
            : FinancialResult::unknown([['code' => 'ITEM_NOT_STOCKED', 'detail' => 'An unstocked item has no stock cost.']]);

        return new ResolvedSource($this->kind(), $item->id, $item->code.' — '.$item->name, $item->stock_uom_id, $quantity, $cost, ['cost_source' => 'inventory.query-material-cost']);
    }
}
