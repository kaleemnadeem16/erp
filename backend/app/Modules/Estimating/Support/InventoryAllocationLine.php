<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Inventory\Support\StockBalance;
use Brick\Math\BigDecimal;

/** A quantity to draw from one location/batch balance. `lineCost` is null without cost evidence. */
final class InventoryAllocationLine
{
    public function __construct(
        public readonly StockBalance $balance,
        public readonly BigDecimal $quantity,
        public readonly ?BigDecimal $lineCost,
    ) {}
}
