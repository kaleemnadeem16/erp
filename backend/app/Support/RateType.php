<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which rate applies to which document is an explicit rule, not an accident —
 * see docs/domain/multi-currency.md#rate-selection: Spot for invoices/receipts/
 * purchases at document date, Closing for period-end reporting, Average for
 * comparative reporting over a period.
 */
enum RateType: string
{
    case Spot = 'SPOT';
    case Average = 'AVERAGE';
    case Closing = 'CLOSING';
}
