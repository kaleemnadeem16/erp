<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Support\FiscalCalendar;
use App\Modules\Numbering\Support\FiscalPosition;
use Carbon\CarbonImmutable;

/** The default when no accounting calendar is bound: nothing is covered, so fiscal variables are refused rather than guessed. */
final class NullFiscalCalendar implements FiscalCalendar
{
    public function forDate(CarbonImmutable $localDate): ?FiscalPosition
    {
        return null;
    }
}
