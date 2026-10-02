<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Numbering\Support\FiscalCalendar;
use App\Modules\Numbering\Support\FiscalPosition;
use Carbon\CarbonImmutable;

/** Fulfils the numbering framework's fiscal-calendar port from the ledger's fiscal years and periods. */
final class AccountingFiscalCalendar implements FiscalCalendar
{
    public function forDate(CarbonImmutable $localDate): ?FiscalPosition
    {
        $date = $localDate->toDateString();
        $year = FiscalYear::query()->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
        if ($year === null) {
            return null;
        }
        $period = AccountingPeriod::query()->where('fiscal_year_id', $year->id)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();

        return new FiscalPosition(
            (string) $year->id,
            CarbonImmutable::parse($year->starts_on),
            CarbonImmutable::parse($year->ends_on),
            $period !== null ? (string) $period->id : 'none',
            $period !== null ? $period->period_no : 0,
        );
    }
}
