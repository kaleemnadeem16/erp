<?php

namespace Tests\Unit\Modules\Tax;

use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxRefusal;
use PHPUnit\Framework\TestCase;

class EffectivePeriodTest extends TestCase
{
    public function test_adjacent_periods_cover_the_boundary_once_and_open_periods_have_no_end(): void
    {
        $first = new EffectivePeriod('2028-02-01', '2028-03-01');
        $next = new EffectivePeriod('2028-03-01');
        $this->assertFalse($first->covers('2028-01-31'));
        $this->assertTrue($first->covers('2028-02-01'));
        $this->assertTrue($first->covers('2028-02-29'));
        $this->assertFalse($first->covers('2028-03-01'));
        $this->assertTrue($next->covers('2028-03-01'));
        $this->assertTrue($next->covers('2099-12-31'));
    }

    public function test_invalid_dates_and_non_positive_intervals_refuse_with_a_tax_reason(): void
    {
        foreach ([['2026-02-29', null], ['2026-2-01', null], ['2026-01-01T00:00:00Z', null],
            ['2026-01-01', '2026-02-30'], ['2026-01-01', '2026-01-01'], ['2026-02-01', '2026-01-01']] as [$from, $to]) {
            try {
                new EffectivePeriod($from, $to);
                $this->fail('Invalid Tax effective period was accepted.');
            } catch (TaxRefusal $e) {
                $this->assertSame('TAX_PERIOD_INVALID', $e->reasonCode);
            }
        }
    }
}
