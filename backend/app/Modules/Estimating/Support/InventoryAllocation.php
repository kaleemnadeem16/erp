<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Inventory\Support\Costing\CostResolution;
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
        public readonly ?CostResolution $cost = null,
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

    /**
     * The same draw with each line and the whole priced through Inventory.
     *
     * @param  list<CostResolution>  $lineCosts  one per line, in order
     */
    public function priced(array $lineCosts, ?CostResolution $total): self
    {
        $lines = [];
        foreach ($this->lines as $index => $line) {
            $lines[] = new InventoryAllocationLine($line->balance, $line->quantity, $lineCosts[$index]->totalCost, $lineCosts[$index]);
        }

        return new self($this->strategy, $lines, $this->covered, $this->shortage, $total?->totalCost, $this->criteria, $this->limitations, $total);
    }
}
