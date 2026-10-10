<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Inventory\Support\Costing\CostResolution;
use Brick\Math\BigDecimal;

/** Separate standard/planned scenarios. Incomplete unmatched subtotals cannot establish impact. */
final readonly class VarianceCostComparison
{
    public function __construct(public CostResolution $standard, public CostResolution $planned) {}

    public function delta(): ?BigDecimal
    {
        return $this->standard->totalCost === null || $this->planned->totalCost === null ? null : $this->planned->totalCost->minus($this->standard->totalCost);
    }
}
