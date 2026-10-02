<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Modules\Inventory\Actions\Backdating\BackdatingHorizonEvaluator;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Support\InventoryCutoverMode;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
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
            'timezone' => 'Pacific/Kiritimati',
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

    // --- ADR 0036 cutover boundary composition ---

    private function cutover(string $cutoverAt, InventoryCutoverStatus $status): InventoryCutover
    {
        $actor = User::factory()->create()->id;
        $now = Carbon::now();

        // ic_audit_shape_check requires each status to carry the audit trail
        // the states before it would have written.
        $trail = match ($status) {
            InventoryCutoverStatus::Draft, InventoryCutoverStatus::Failed => [],
            InventoryCutoverStatus::Validated => ['validated_by' => $actor, 'validated_at' => $now],
            InventoryCutoverStatus::Approved => ['validated_by' => $actor, 'validated_at' => $now, 'approved_by' => $actor, 'approved_at' => $now],
            InventoryCutoverStatus::Finalised => ['validated_by' => $actor, 'validated_at' => $now, 'approved_by' => $actor, 'approved_at' => $now, 'finalised_by' => $actor, 'finalised_at' => $now],
        };

        return InventoryCutover::query()->create([
            'mode' => InventoryCutoverMode::OpeningState->value,
            'cutover_at' => $cutoverAt,
            'source_system' => 'LEGACY',
            'target_valuation_policy' => [],
            'status' => $status->value,
            'control_quantity' => '0.0000', 'control_value' => '0.0000', 'base_control_value' => '0.0000',
            'created_by' => $actor,
            ...$trail,
        ]);
    }

    public function test_a_finalised_cutover_floors_a_horizon_that_would_otherwise_permit_earlier(): void
    {
        $company = $this->configuredCompany([
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2026-01-01',
        ]);
        $this->cutover('2026-03-15 09:00:00', InventoryCutoverStatus::Finalised);

        $beforeCutover = $this->evaluator()->evaluate($company, Carbon::parse('2026-02-10'));
        $this->assertFalse($beforeCutover->withinHorizon);
        $this->assertTrue($beforeCutover->permittedFloor->isSameDay(Carbon::parse('2026-03-15')));

        $afterCutover = $this->evaluator()->evaluate($company, Carbon::parse('2026-03-20'));
        $this->assertTrue($afterCutover->withinHorizon);
    }

    public function test_an_approved_cutover_binds_before_it_is_finalised(): void
    {
        $company = $this->configuredCompany([
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2026-01-01',
        ]);
        $this->cutover('2026-03-15 09:00:00', InventoryCutoverStatus::Approved);

        $this->assertFalse($this->evaluator()->evaluate($company, Carbon::parse('2026-02-10'))->withinHorizon);
    }

    public function test_a_draft_cutover_does_not_bind(): void
    {
        $company = $this->configuredCompany([
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2026-01-01',
        ]);
        $this->cutover('2026-03-15 09:00:00', InventoryCutoverStatus::Draft);

        $result = $this->evaluator()->evaluate($company, Carbon::parse('2026-02-10'));
        $this->assertTrue($result->withinHorizon);
        $this->assertTrue($result->permittedFloor->isSameDay(Carbon::parse('2026-01-01')));
    }

    public function test_the_stricter_of_the_horizon_floor_and_the_cutover_wins(): void
    {
        $company = $this->configuredCompany([
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2026-06-01',
        ]);
        $this->cutover('2026-03-15 09:00:00', InventoryCutoverStatus::Finalised);

        // The horizon floor is later than the cutover here, so it stays the
        // binding constraint and the cutover does not loosen it.
        $result = $this->evaluator()->evaluate($company, Carbon::parse('2026-04-01'));
        $this->assertFalse($result->withinHorizon);
        $this->assertTrue($result->permittedFloor->isSameDay(Carbon::parse('2026-06-01')));
    }

    public function test_a_cutover_supplies_a_floor_under_period_lock_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-15'));
        $company = $this->configuredCompany(['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY']);
        $fiscalYear = FiscalYear::query()->create(['name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $this->addPeriod($fiscalYear, 1, 'Feb 2026', '2026-02-01', '2026-02-28', PeriodStatus::Open);
        $this->cutover('2026-03-15 09:00:00', InventoryCutoverStatus::Finalised);

        // The period is open, so the mode alone would permit this date.
        $result = $this->evaluator()->evaluate($company, Carbon::parse('2026-02-10'));
        $this->assertFalse($result->withinHorizon);
        $this->assertTrue($result->permittedFloor->isSameDay(Carbon::parse('2026-03-15')));
    }

    public function test_a_movement_earlier_the_same_day_as_the_cutover_instant_is_refused(): void
    {
        $company = $this->configuredCompany([
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2026-01-01',
        ]);
        $this->cutover('2026-03-15 09:00:00', InventoryCutoverStatus::Finalised);

        $this->assertFalse($this->evaluator()->evaluate($company, Carbon::parse('2026-03-15 08:59:59'))->withinHorizon);
        $this->assertTrue($this->evaluator()->evaluate($company, Carbon::parse('2026-03-15 09:00:00'))->withinHorizon);
    }
}
