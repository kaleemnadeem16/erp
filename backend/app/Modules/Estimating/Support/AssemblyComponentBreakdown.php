<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Modules\Estimating\Models\AssemblyComponent;
use Brick\Math\BigDecimal;

/**
 * One component's contribution to the roll-up, kept explainable per
 * docs/decisions (Step 3): resolvedQtyPerWorkUnit and resolvedUnitCost are
 * the actual numbers used, costSource says where the unit cost came from
 * (AVCO, a specific formulation, a resource rate as of a date, or a manual
 * override) — enough to answer "what calculation base/value was used"
 * without re-deriving it from the raw component row.
 */
final readonly class AssemblyComponentBreakdown
{
    public function __construct(
        public AssemblyComponent $component,
        public BigDecimal $resolvedQtyPerWorkUnit,
        public BigDecimal $resolvedUnitCost,
        public BigDecimal $lineCost,
        public string $costSource,
    ) {}
}
