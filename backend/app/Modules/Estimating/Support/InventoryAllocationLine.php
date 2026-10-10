<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\StockBalance;
use Brick\Math\BigDecimal;

/**
 * A quantity to draw from one location/batch balance. `lineCost` is null without a complete cost for this draw;
 * `cost` is the SELECTED_DRAW resolution behind it once the draw has been priced through Inventory.
 */
final class InventoryAllocationLine
{
    public function __construct(
        public readonly StockBalance $balance,
        public readonly BigDecimal $quantity,
        public readonly ?BigDecimal $lineCost,
        public readonly ?CostResolution $cost = null,
    ) {}
}
