<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Fx;

use Carbon\CarbonImmutable;

/**
 * A date-only half-open period `[from, endExclusive)` (ADR 0118). A screen that says "through 31 October" stores 1 November exclusive, so two adjacent
 * periods meet without a gap and without both covering the same day. A null end is open-ended.
 */
final readonly class EffectivePeriod
{
    public function __construct(public string $from, public ?string $endExclusive = null)
    {
        FinancialDate::assertCalendarDate($from);
        if ($endExclusive !== null) {
            FinancialDate::assertCalendarDate($endExclusive);
            if ($endExclusive <= $from) {
                throw FxRefusal::make(FxRefusal::FINANCIAL_DATE_INVALID, 'A period must end after it starts.', 'end');
            }
        }
    }

    /** The period a person describes as "from … through (and including) …". */
    public static function throughInclusive(string $from, string $lastDay): self
    {
        FinancialDate::assertCalendarDate($lastDay);

        return new self($from, CarbonImmutable::createFromFormat('!Y-m-d', $lastDay, 'UTC')->addDay()->format('Y-m-d'));
    }

    public function covers(string $date): bool
    {
        return $date >= $this->from && ($this->endExclusive === null || $date < $this->endExclusive);
    }
}
