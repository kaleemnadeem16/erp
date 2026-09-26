<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use App\Support\UomConversionService;
use Brick\Math\BigDecimal;

/**
 * ADR 0050's item-aware conversion lookup: an item_quantity_units override
 * for this item and UoM resolves first — and, unlike UomConversionService,
 * may cross UoM dimension (roll↔metre, sheet↔m²) — before falling back to
 * the existing global, dimension-locked service. Never instead of it: every
 * caller that doesn't need an item-specific factor keeps using
 * UomConversionService directly. See
 * docs/domain/units-of-measure.md#item-specific-conversions.
 */
final class ItemUomConversionService
{
    public function __construct(private readonly UomConversionService $conversion) {}

    /**
     * 1 unit of $from = the returned factor × $item's own stock UoM. Throws
     * InvalidArgumentException (same as UomConversionService::convert(), and
     * only when no override exists) if $from shares no dimension with the
     * item's stock UoM either.
     */
    public function stockUnitFactor(Item $item, Uom $from): BigDecimal
    {
        $override = ItemQuantityUnit::query()->where('item_id', $item->id)->where('uom_id', $from->id)->first();

        return $override !== null
            ? BigDecimal::of($override->factor)
            : $this->conversion->convert('1', $from, $item->stockUom);
    }
}
