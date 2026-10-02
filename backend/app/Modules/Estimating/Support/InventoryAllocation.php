<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use Brick\Math\BigDecimal;

/**
 * The outcome of AllocateInventory for one item: a draw plan, what it covers
 * and what it cannot, and the criteria evidence behind it. Advisory — no stock
 * is reserved. `estimatedCost` is null unless every drawn line has cost
 * evidence; a partial sum would silently understate the cost.
 */
final class InventoryAllocation
{
    /**
     * @param  list<InventoryAllocationLine>  $lines
     * @param  list<AllocationCriterionReport>  $criteria
     * @param  list<string>  $limitations
     */
    public function __construct(
        public readonly MaterialSelectionStrategy $strategy,
        public readonly array $lines,
        public readonly BigDecimal $covered,
        public readonly BigDecimal $shortage,
        public readonly ?BigDecimal $estimatedCost,
        public readonly array $criteria,
        public readonly array $limitations,
    ) {}

    public function pickCount(): int
    {
        return count($this->lines);
    }

    /** Order-insensitive identity of the draw, for "same as the recommendation" comparisons. */
    public function signature(): string
    {
        $parts = array_map(
            fn (InventoryAllocationLine $line): string => $line->balance->key().'='.$line->quantity->stripTrailingZeros(),
            $this->lines,
        );
        sort($parts);

        return implode('|', $parts);
    }
}
