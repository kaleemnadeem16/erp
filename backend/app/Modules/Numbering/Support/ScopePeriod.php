<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** How often a counter restarts (ADR 0079 §6). */
enum ScopePeriod: string
{
    case None = 'NONE';
    case Year = 'YEAR';
    case Month = 'MONTH';
    case Day = 'DAY';
    case FiscalYear = 'FISCAL_YEAR';
    case FiscalPeriod = 'FISCAL_PERIOD';

    /** Needs "what day is it for the company", so the company time zone. */
    public function needsDate(): bool
    {
        return $this !== self::None;
    }

    public function needsFiscalCalendar(): bool
    {
        return $this === self::FiscalYear || $this === self::FiscalPeriod;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'never (one counter)',
            self::Year => 'every calendar year',
            self::Month => 'every calendar month',
            self::Day => 'every day',
            self::FiscalYear => 'every fiscal year',
            self::FiscalPeriod => 'every fiscal period',
        };
    }
}
