<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/** ADR 0040's backdating horizon modes — see docs/domain/stock-recalculation.md "Backdating horizon". */
enum BackdatingHorizonMode: string
{
    case CurrentAndPreviousPeriod = 'CURRENT_AND_PREVIOUS_PERIOD';
    case PeriodLockOnly = 'PERIOD_LOCK_ONLY';
    case PostingFloor = 'POSTING_FLOOR';
}
