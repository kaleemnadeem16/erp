<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

/**
 * Shared by FiscalYear and AccountingPeriod — see docs/domain/general-ledger.md
 * "Periods". Posting into a CLOSED period/year is rejected (enforced when
 * posting exists, Phase 6) — not the same lifecycle as App\Support\Status
 * (ACTIVE/ARCHIVED), which is about record visibility, not postability.
 */
enum PeriodStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';
}
