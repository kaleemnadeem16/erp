<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

use Carbon\CarbonImmutable;

/** A port: the numbering engine asks the fiscal calendar a question and knows nothing about accounting tables. */
interface FiscalCalendar
{
    /** Null when no fiscal year covers the date. */
    public function forDate(CarbonImmutable $localDate): ?FiscalPosition;
}
