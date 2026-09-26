<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The one place OverheadPolicy.rate's meaning is interpreted — see
 * OverheadDriverType's docblock for what each driver means. A pure
 * function deliberately: Step 3 (the remaining Assembly slice) will call
 * this with real assembly_components totals, but nothing here needs those
 * rows to exist yet, so the driver semantics are fully testable now.
 */
final class OverheadAmountCalculator
{
    public function __invoke(OverheadDriverType $driver, string $rate, OverheadCostBasis $basis): BigDecimal
    {
        $rate = BigDecimal::of($rate);

        $amount = match ($driver) {
            OverheadDriverType::PctMaterial => $this->percentageOf($rate, $basis->materialCost),
            OverheadDriverType::PctLabour => $this->percentageOf($rate, $basis->labourCost),
            OverheadDriverType::PctDirectCost => $this->percentageOf($rate, $this->directCost($basis)),
            OverheadDriverType::PerLabourHour => $rate->multipliedBy(BigDecimal::of($basis->labourHours)),
            OverheadDriverType::PerWorkUnit => $rate->multipliedBy(BigDecimal::of($basis->workQty)),
        };

        return $amount->toScale(4, RoundingMode::HALF_UP);
    }

    private function directCost(OverheadCostBasis $basis): BigDecimal
    {
        return BigDecimal::of($basis->materialCost)
            ->plus($basis->labourCost)
            ->plus($basis->plantCost)
            ->plus($basis->subcontractCost);
    }

    private function percentageOf(BigDecimal $ratePct, string|BigDecimal $of): BigDecimal
    {
        return BigDecimal::of($of)->multipliedBy($ratePct->dividedBy(100, 10, RoundingMode::HALF_UP));
    }
}
