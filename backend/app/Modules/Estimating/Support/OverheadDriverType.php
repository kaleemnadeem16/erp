<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * ADR 0048's five v1 drivers. `rate` on OverheadPolicy means different
 * things depending on which of these is chosen — see OverheadAmountCalculator,
 * the one place that interprets it:
 *
 *   PCT_MATERIAL     — percentage (0-100) of material cost
 *   PCT_LABOUR       — percentage (0-100) of labour cost
 *   PCT_DIRECT_COST  — percentage (0-100) of material+labour+plant+subcontract
 *   PER_LABOUR_HOUR  — currency amount per labour hour
 *   PER_WORK_UNIT    — currency amount per unit of the assembly's work_uom
 *
 * Machine/work-centre-hour and generic UOM-based allocation were
 * deliberately folded into PER_WORK_UNIT/PLANT rather than built as
 * separate drivers — see ADR 0048 "Drivers".
 */
enum OverheadDriverType: string
{
    case PctMaterial = 'PCT_MATERIAL';
    case PctLabour = 'PCT_LABOUR';
    case PctDirectCost = 'PCT_DIRECT_COST';
    case PerLabourHour = 'PER_LABOUR_HOUR';
    case PerWorkUnit = 'PER_WORK_UNIT';

    public function isPercentage(): bool
    {
        return match ($this) {
            self::PctMaterial, self::PctLabour, self::PctDirectCost => true,
            self::PerLabourHour, self::PerWorkUnit => false,
        };
    }
}
