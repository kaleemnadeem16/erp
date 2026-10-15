<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Sources;

use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingRefusal;
use App\Support\Status;

/** The checks every Item-backed source shares: it exists in this company (a foreign id is simply not found), is active and sellable, and is priced in its stock unit. */
final class ItemSourceLookup
{
    public static function sellable(PricingInput $input): Item
    {
        $item = Item::query()->find($input->source->id);
        if ($item === null) {
            throw PricingRefusal::make(PricingRefusal::SOURCE_NOT_FOUND, 'The item was not found.', 'source');
        }
        if ($item->status !== Status::Active || ! $item->is_sellable) {
            throw PricingRefusal::make(PricingRefusal::SOURCE_INCOMPATIBLE, 'The item is archived or not sellable.', 'source');
        }
        // Alternate units and named packages need their own conversion evidence; until that adapter exists they refuse rather than guess a factor.
        if ($input->sellingUomId === null || $input->sellingUomId !== $item->stock_uom_id) {
            throw PricingRefusal::make(PricingRefusal::SELLING_UNIT_UNSUPPORTED, 'Only the item\'s stock unit is supported as a selling unit.', 'selling_uom_id');
        }

        // The quantity is proven against this Item's own contract here, before any adapter reads cost.
        return $item;
    }
}
