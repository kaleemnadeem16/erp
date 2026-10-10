<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Models\Uom;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\StockBalance;
use Brick\Math\BigDecimal;

/**
 * One chosen draw of a hand-built mix. `quantity` is the canonical figure, in the item's stock unit; the entered
 * quantity, its unit and the conversion factor are kept only to explain where that figure came from.
 */
final class StockMixLine
{
    public function __construct(
        public readonly StockBalance $balance,
        public readonly BigDecimal $quantity,
        public readonly ?BigDecimal $lineCost,
        public readonly string $enteredQuantity,
        public readonly Uom $enteredUom,
        public readonly BigDecimal $stockUnitFactor,
        public readonly BigDecimal $remainingAfter,
        public readonly ?CostResolution $cost = null,
    ) {}
}
