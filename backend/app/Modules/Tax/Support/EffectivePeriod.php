<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Tax effective dates are calendar dates in a half-open [from, endExclusive) interval, independent of Sales internals. */
final readonly class EffectivePeriod
{
    public function __construct(public string $from, public ?string $endExclusive = null)
    {
        self::assertCalendarDate($from);
        if ($endExclusive !== null) {
            self::assertCalendarDate($endExclusive);
            if ($endExclusive <= $from) {
                throw TaxRefusal::because('TAX_PERIOD_INVALID', 'A Tax period must end after it starts.');
            }
        }
    }

    public function covers(string $date): bool
    {
        self::assertCalendarDate($date);

        return $date >= $this->from && ($this->endExclusive === null || $date < $this->endExclusive);
    }

    private static function assertCalendarDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw TaxRefusal::because('TAX_PERIOD_INVALID', 'A Tax date must be a real calendar date written YYYY-MM-DD.');
        }
    }
}
