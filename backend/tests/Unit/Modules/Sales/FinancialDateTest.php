<?php

namespace Tests\Unit\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Support\Fx\EffectivePeriod;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Modules\Sales\Support\Fx\FxMode;
use App\Modules\Sales\Support\Fx\FxPolicy;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** FR1 — explicit financial dates, half-open effective periods and policy-version selection (ADR 0118). Pure: no database. */
class FinancialDateTest extends TestCase
{
    private function refused(callable $call, string $code): void
    {
        try {
            $call();
            $this->fail("Expected a refusal with {$code}.");
        } catch (DomainInvariantException $refusal) {
            $this->assertSame($code, $refusal->reasonCode);
        }
    }

    public function test_local_midnight_gives_a_different_date_from_utc(): void
    {
        $instant = CarbonImmutable::parse('2026-10-14 20:30:00', 'UTC');

        $karachi = FinancialDate::forInstant($instant, 'Asia/Karachi');
        $utc = FinancialDate::forInstant($instant, 'UTC');
        $newYork = FinancialDate::forInstant($instant, 'America/New_York');

        $this->assertSame('2026-10-15', $karachi->date, '01:30 on the 15th in Karachi');
        $this->assertSame('2026-10-14', $utc->date);
        $this->assertSame('2026-10-14', $newYork->date);
        $this->assertSame('2026-10-14T20:30:00+00:00', $karachi->observedAtUtc->format('c'), 'The observation stays UTC.');
        $this->assertSame('UTC', $karachi->observedAtUtc->timezoneName);
    }

    public function test_an_instant_given_in_another_zone_is_normalised_to_utc(): void
    {
        $date = FinancialDate::forInstant(CarbonImmutable::parse('2026-10-15 01:30:00', 'Asia/Karachi'), 'Asia/Karachi');

        $this->assertSame('2026-10-15', $date->date);
        $this->assertSame('2026-10-14T20:30:00+00:00', $date->observedAtUtc->format('c'));
    }

    public function test_an_explicit_date_must_be_a_real_calendar_date_and_zone(): void
    {
        $now = CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC');
        $this->assertSame('2028-02-29', FinancialDate::explicit('2028-02-29', 'UTC', $now)->date);
        foreach (['2026-02-30', '2026-2-3', '15/10/2026', '', '2026-13-01', '2026-10-15 00:00:00'] as $bad) {
            $this->refused(fn () => FinancialDate::explicit($bad, 'UTC', $now), 'FINANCIAL_DATE_INVALID');
        }
        $this->refused(fn () => FinancialDate::explicit('2026-10-15', 'Mars/Olympus', $now), 'FINANCIAL_DATE_INVALID');
        $this->refused(fn () => FinancialDate::forInstant($now, 'nowhere'), 'FINANCIAL_DATE_INVALID');
    }

    public function test_days_since_counts_whole_calendar_days_across_month_and_leap_boundaries(): void
    {
        $now = CarbonImmutable::parse('2028-03-01 00:00:00', 'UTC');
        $date = FinancialDate::explicit('2028-03-01', 'UTC', $now);

        $this->assertSame(0, $date->daysSince('2028-03-01'));
        $this->assertSame(1, $date->daysSince('2028-02-29'));
        $this->assertSame(2, $date->daysSince('2028-02-28'));
        $this->assertSame(-1, $date->daysSince('2028-03-02'), 'A later date is negative, never an age.');
    }

    public function test_a_through_date_is_stored_as_the_next_day_exclusive_and_adjacent_periods_never_overlap(): void
    {
        $october = EffectivePeriod::throughInclusive('2026-10-01', '2026-10-31');
        $november = new EffectivePeriod('2026-11-01', '2026-12-01');

        $this->assertSame('2026-11-01', $october->endExclusive);
        $this->assertTrue($october->covers('2026-10-01'));
        $this->assertTrue($october->covers('2026-10-31'));
        $this->assertFalse($october->covers('2026-11-01'));
        $this->assertFalse($october->covers('2026-09-30'));
        foreach (['2026-09-30', '2026-10-01', '2026-10-31', '2026-11-01', '2026-11-30', '2026-12-01'] as $day) {
            $this->assertLessThanOrEqual(1, (int) $october->covers($day) + (int) $november->covers($day), "{$day} matched both");
        }
        $this->assertTrue((new EffectivePeriod('2026-10-01'))->covers('2099-01-01'), 'An open end never expires.');
        $this->assertSame('2027-01-01', EffectivePeriod::throughInclusive('2026-12-01', '2026-12-31')->endExclusive);
    }

    public function test_an_empty_or_malformed_period_is_refused(): void
    {
        $this->refused(fn () => new EffectivePeriod('2026-10-01', '2026-10-01'), 'FINANCIAL_DATE_INVALID');
        $this->refused(fn () => new EffectivePeriod('2026-10-02', '2026-10-01'), 'FINANCIAL_DATE_INVALID');
        $this->refused(fn () => new EffectivePeriod('2026-10-32'), 'FINANCIAL_DATE_INVALID');
    }

    public function test_policy_validation_and_selection(): void
    {
        $this->assertSame(FxMode::ExactDate, FxPolicy::builtInExactDate()->mode);
        $this->assertNull(FxPolicy::builtInExactDate()->version);
        $this->refused(fn () => new FxPolicy(1, FxMode::BoundedPrior, -1), 'FX_POLICY_INVALID');
        $this->refused(fn () => new FxPolicy(1, FxMode::ExactDate, 2), 'FX_POLICY_INVALID');
        $this->assertSame(0, FxPolicy::boundedPrior(1, 0)->maxAgeDays, 'Zero is a valid bound.');

        $first = FxPolicy::boundedPrior(1, 3, EffectivePeriod::throughInclusive('2026-01-01', '2026-06-30'));
        $second = FxPolicy::boundedPrior(2, 7, new EffectivePeriod('2026-07-01'));

        $this->assertSame(1, FxPolicy::select([$first, $second], '2026-06-30')->version);
        $this->assertSame(2, FxPolicy::select([$first, $second], '2026-07-01')->version);
        $this->refused(fn () => FxPolicy::select([$first, $second], '2025-12-31'), 'FX_POLICY_MISSING');
        $overlap = FxPolicy::boundedPrior(3, 1, new EffectivePeriod('2026-06-01', '2026-08-01'));
        $this->refused(fn () => FxPolicy::select([$first, $second, $overlap], '2026-06-15'), 'FX_POLICY_AMBIGUOUS');
        $this->refused(fn () => FxPolicy::select([$first, $second, $overlap], '2026-07-31'), 'FX_POLICY_AMBIGUOUS');
    }
}
