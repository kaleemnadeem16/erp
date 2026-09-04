<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Modules\Inventory\Actions\Backdating\BackdatingHorizonEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BackdatingHorizonEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function evaluator(): BackdatingHorizonEvaluator
    {
        return app(BackdatingHorizonEvaluator::class);
    }

    private function configuredCompany(array $attributes): Company
    {
        $company = Company::query()->findOrFail(1);
        $company->fill($attributes)->save();

        return $company->refresh();
    }

    private function addPeriod(FiscalYear $fiscalYear, int $periodNo, string $name, string $startsOn, string $endsOn, PeriodStatus $status): AccountingPeriod
    {
        return $fiscalYear->periods()->create([
            'period_no' => $periodNo, 'name' => $name,
            'starts_on' => $startsOn, 'ends_on' => $endsOn, 'status' => $status->value,
        ]);
    }

    // --- POSTING_FLOOR / FIXED_DATE ---

    public function test_fixed_date_floor_permits_the_floor_date_and_later(): void
    {
        $company = $this->configuredCompany([
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2026-03-01',
        ]);

        $onFloor = $this->evaluator()->evaluate($company, Carbon::parse('2026-03-01'));
        $this->assertTrue($onFloor->withinHorizon);
        $this->assertTrue($onFloor->permittedFloor->isSameDay(Carbon::parse('2026-03-01')));

        $beforeFloor = $this->evaluator()->evaluate($company, Carbon::parse('2026-02-28'));
        $this->assertFalse($beforeFloor->withinHorizon);
    }

    // --- POSTING_FLOOR / ROLLING_FORMULA ---

    public function test_rolling_floor_uses_the_company_accounting_timezone_not_utc(): void
    {
        // 2026-06-15 23:30 UTC is already 2026-06-16 13:30 in Pacific/Kiritimati
        // (UTC+14) — a floor computed off the wrong timezone (UTC, or PHP's
        // default app timezone) would be one calendar day earlier than correct.
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 23, 30, 0, 'UTC'));
        $company = $this->configuredCompany([
            'accounting_timezone' => 'Pacific/Kiritimati',
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'ROLLING_FORMULA',
            'backdating_posting_floor_rolling_days' => 10,
        ]);

        $onFloor = $this->evaluator()->evaluate($company, Carbon::parse('2026-06-06'));
        $this->assertTrue($onFloor->withinHorizon);
        $this->assertTrue($onFloor->permittedFloor->isSameDay(Carbon::parse('2026-06-06')));

        $beforeFloor = $this->evaluator()->evaluate($company, Carbon::parse('2026-06-05'));
        $this->assertFalse($beforeFloor->withinHorizon);
    }

    // --- PERIOD_LOCK_ONLY ---

    public function test_period_lock_only_permits_a_date_inside_an_open_period_regardless_of_age(): void
    {
        $company = $this->configuredCompany(['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY']);
        $fiscalYear = FiscalYear::query()->create(['name' => 'FY2020', 'starts_on' => '2020-01-01', 'ends_on' => '2020-12-31']);
        $this->addPeriod($fiscalYear, 1, 'Jan 2020', '2020-01-01', '2020-01-31', PeriodStatus::Open);

        $result = $this->evaluator()->evaluate($company, Carbon::parse('2020-01-15'));

        $this->assertTrue($result->withinHorizon);
        $this->assertNull($result->permittedFloor);
    }

    public function test_period_lock_only_blocks_a_date_inside_a_closed_period(): void
    {
        $company = $this->configuredCompany(['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY']);
        $fiscalYear = FiscalYear::query()->create(['name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->addPeriod($fiscalYear, 1, 'Jan 2026', '2026-01-01', '2026-01-31', PeriodStatus::Closed);

        $result = $this->evaluator()->evaluate($company, Carbon::parse('2026-01-15'));

        $this->assertFalse($result->withinHorizon);
    }

    public function test_period_lock_only_blocks_a_date_with_no_covering_period(): void
    {
        $company = $this->configuredCompany(['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY']);

        $result = $this->evaluator()->evaluate($company, Carbon::parse('2026-01-15'));

        $this->assertFalse($result->withinHorizon);
    }

    // --- CURRENT_AND_PREVIOUS_PERIOD ---

    public function test_current_and_previous_period_permits_the_current_period_floored_at_the_open_previous_period_start(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-15'));
        $company = $this->configuredCompany(['backdating_horizon_mode' => 'CURRENT_AND_PREVIOUS_PERIOD']);
        $fiscalYear = FiscalYear::query()->create(['name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->addPeriod($fiscalYear, 1, 'Jan 2026', '2026-01-01', '2026-01-31', PeriodStatus::Open);
        $this->addPeriod($fiscalYear, 2, 'Feb 2026', '2026-02-01', '2026-02-28', PeriodStatus::Open);

        $inCurrentPeriod = $this->evaluator()->evaluate($company, Carbon::parse('2026-02-10'));
        $this->assertTrue($inCurrentPeriod->withinHorizon);

        $inOpenPreviousPeriod = $this->evaluator()->evaluate($company, Carbon::parse('2026-01-15'));
        $this->assertTrue($inOpenPreviousPeriod->withinHorizon);
        $this->assertTrue($inOpenPreviousPeriod->permittedFloor->isSameDay(Carbon::parse('2026-01-01')));

        $beforePreviousPeriod = $this->evaluator()->evaluate($company, Carbon::parse('2025-12-20'));
        $this->assertFalse($beforePreviousPeriod->withinHorizon);
    }

    public function test_current_and_previous_period_tightens_the_floor_to_the_current_period_start_when_the_previous_period_is_closed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-15'));
        $company = $this->configuredCompany(['backdating_horizon_mode' => 'CURRENT_AND_PREVIOUS_PERIOD']);
        $fiscalYear = FiscalYear::query()->create(['name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->addPeriod($fiscalYear, 1, 'Jan 2026', '2026-01-01', '2026-01-31', PeriodStatus::Closed);
        $this->addPeriod($fiscalYear, 2, 'Feb 2026', '2026-02-01', '2026-02-28', PeriodStatus::Open);

        $result = $this->evaluator()->evaluate($company, Carbon::parse('2026-01-20'));

        $this->assertFalse($result->withinHorizon);
        $this->assertTrue($result->permittedFloor->isSameDay(Carbon::parse('2026-02-01')));
    }

    public function test_current_and_previous_period_throws_when_no_period_covers_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-15'));
        $company = $this->configuredCompany(['backdating_horizon_mode' => 'CURRENT_AND_PREVIOUS_PERIOD']);

        $this->expectException(DomainInvariantException::class);
        $this->evaluator()->evaluate($company, Carbon::parse('2026-01-01'));
    }
}
