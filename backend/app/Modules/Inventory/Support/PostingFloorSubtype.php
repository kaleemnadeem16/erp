<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/** Set only when BackdatingHorizonMode::PostingFloor is selected. */
enum PostingFloorSubtype: string
{
    case FixedDate = 'FIXED_DATE';
    case RollingFormula = 'ROLLING_FORMULA';
}
