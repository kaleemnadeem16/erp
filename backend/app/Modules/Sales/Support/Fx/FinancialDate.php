<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Fx;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * An explicit financial date (ADR 0118): a calendar date in the company's central time zone, plus the UTC instant it was observed. The date is what
 * rates and effective periods are judged against; the instant is only provenance. The pricing, issue, expiry and tax dates are different facts, so a
 * caller names the one it means rather than this class reading "today" implicitly.
 */
final readonly class FinancialDate
{
    private function __construct(public string $date, public string $timeZone, public CarbonImmutable $observedAtUtc) {}

    /** The company-local calendar date of an instant: 20:30 UTC on the 14th is already the 15th in Karachi. */
    public static function forInstant(CarbonInterface $instant, string $timeZone): self
    {
        $zone = self::zone($timeZone);
        $utc = CarbonImmutable::instance($instant)->utc();

        return new self($utc->setTimezone($zone)->format('Y-m-d'), $timeZone, $utc);
    }

    /** A date the caller chose (a deliberate pricing date). Future dates are allowed here; they simply find no rate, because nothing is substituted. */
    public static function explicit(string $date, string $timeZone, CarbonInterface $observedAt): self
    {
        self::zone($timeZone);
        self::assertCalendarDate($date);

        return new self($date, $timeZone, CarbonImmutable::instance($observedAt)->utc());
    }

    /** A real calendar date written exactly YYYY-MM-DD; anything else (2026-02-30, 2026-2-3, a time part) is refused. */
    public static function assertCalendarDate(string $date, string $field = 'date'): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw FxRefusal::make(FxRefusal::FINANCIAL_DATE_INVALID, 'The date must be a real calendar date written YYYY-MM-DD.', $field);
        }
    }

    /** Whole calendar days from `$earlier` to this date (negative if `$earlier` is later). */
    public function daysSince(string $earlier): int
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $earlier, 'UTC');
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $this->date, 'UTC');

        return (int) $from->diffInDays($to, false);
    }

    private static function zone(string $timeZone): DateTimeZone
    {
        try {
            return new DateTimeZone($timeZone);
        } catch (Throwable) {
            throw FxRefusal::make(FxRefusal::FINANCIAL_DATE_INVALID, 'The company time zone is not a valid IANA zone.', 'time_zone');
        }
    }
}
