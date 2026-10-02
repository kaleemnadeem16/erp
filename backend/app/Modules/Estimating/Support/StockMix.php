<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use Brick\Math\BigDecimal;

/**
 * A user-chosen draw for one item, validated and priced by PriceStockMix. Quantities are in the item's stock unit
 * against location/batch balances — the same representation an automatic proposal uses — so a future package-aware
 * mix adds package identity to these lines without changing them. Advisory: nothing is reserved.
 * `estimatedCost` is null unless every line has cost evidence.
 */
final class StockMix
{
    /** @param list<StockMixLine> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly BigDecimal $covered,
        public readonly BigDecimal $shortage,
        public readonly ?BigDecimal $estimatedCost,
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
