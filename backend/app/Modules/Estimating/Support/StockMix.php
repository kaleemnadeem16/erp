<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Inventory\Support\Costing\CostResolution;
use Brick\Math\BigDecimal;

/**
 * A user-chosen draw for one item, validated and priced by PriceStockMix. Quantities are in the item's stock unit
 * against location/batch balances — the same representation an automatic proposal uses — so a future package-aware
 * mix adds package identity to these lines without changing them. Advisory: nothing is reserved.
 * `estimatedCost` is the cost of the chosen draw only, null unless every line has a complete cost; `cost` is its
 * SELECTED_DRAW resolution. A draw that leaves a shortage can still be fully costed.
 */
final class StockMix
{
    /** @param list<StockMixLine> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly BigDecimal $covered,
        public readonly BigDecimal $shortage,
        public readonly ?BigDecimal $estimatedCost,
        public readonly ?CostResolution $cost = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->shortage->isZero();
    }

    /** Order-insensitive identity, comparable with InventoryAllocation::signature(). */
    public function signature(): string
    {
        $parts = array_map(
            fn (StockMixLine $line): string => $line->balance->key().'='.$line->quantity->stripTrailingZeros(),
            $this->lines,
        );
        sort($parts);

        return implode('|', $parts);
    }
}
