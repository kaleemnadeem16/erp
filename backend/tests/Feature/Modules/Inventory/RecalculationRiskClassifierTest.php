<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Company;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Modules\Inventory\Actions\Recalculation\RecalculationRiskClassifier;
use App\Modules\Inventory\Support\RecalculationRiskClassification;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecalculationRiskClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function classifier(): RecalculationRiskClassifier
    {
        return app(RecalculationRiskClassifier::class);
    }

    public function test_no_hard_trigger_and_no_threshold_is_normal_risk(): void
    {
        $company = Company::query()->findOrFail(1);

        $result = $this->classifier()->classify($company, Carbon::parse('2026-01-05'), BigDecimal::of('500.0000'));

        $this->assertSame(RecalculationRiskClassification::NormalRisk, $result);
    }

    public function test_a_closed_period_overlapping_the_replay_window_is_high_risk(): void
    {
        $company = Company::query()->findOrFail(1);
        $fiscalYear = FiscalYear::query()->create(['name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        AccountingPeriod::query()->create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 1, 'name' => 'Jan 2026',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => PeriodStatus::Closed,
        ]);

        $result = $this->classifier()->classify($company, Carbon::parse('2026-01-05'), BigDecimal::of('500.0000'));

        $this->assertSame(RecalculationRiskClassification::HighRisk, $result);
    }

    public function test_a_closed_period_entirely_before_the_proposed_date_does_not_trigger(): void
    {
        $company = Company::query()->findOrFail(1);
        $fiscalYear = FiscalYear::query()->create(['name' => 'FY2025', 'starts_on' => '2025-01-01', 'ends_on' => '2025-12-31']);
        AccountingPeriod::query()->create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 1, 'name' => 'Dec 2025',
            'starts_on' => '2025-12-01', 'ends_on' => '2025-12-31', 'status' => PeriodStatus::Closed,
        ]);

        $result = $this->classifier()->classify($company, Carbon::parse('2026-01-05'), BigDecimal::of('500.0000'));

        $this->assertSame(RecalculationRiskClassification::NormalRisk, $result);
    }

    public function test_an_open_period_covering_the_proposed_date_does_not_trigger(): void
    {
        $company = Company::query()->findOrFail(1);
        $fiscalYear = FiscalYear::query()->create(['name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        AccountingPeriod::query()->create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 1, 'name' => 'Jan 2026',
            'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => PeriodStatus::Open,
        ]);

        $result = $this->classifier()->classify($company, Carbon::parse('2026-01-05'), BigDecimal::of('500.0000'));

        $this->assertSame(RecalculationRiskClassification::NormalRisk, $result);
    }

    public function test_a_valuation_adjustment_meeting_the_company_threshold_is_high_risk(): void
    {
        $company = Company::query()->findOrFail(1);
        $company->update(['recalculation_high_risk_threshold_base_amount' => '1000.0000']);

        $result = $this->classifier()->classify($company->fresh(), Carbon::parse('2026-01-05'), BigDecimal::of('-1000.0000'));

        $this->assertSame(RecalculationRiskClassification::HighRisk, $result);
    }

    public function test_a_valuation_adjustment_below_the_company_threshold_is_normal_risk(): void
    {
        $company = Company::query()->findOrFail(1);
        $company->update(['recalculation_high_risk_threshold_base_amount' => '1000.0000']);

        $result = $this->classifier()->classify($company->fresh(), Carbon::parse('2026-01-05'), BigDecimal::of('999.9999'));

        $this->assertSame(RecalculationRiskClassification::NormalRisk, $result);
    }

    public function test_no_threshold_configured_never_triggers_on_magnitude_alone(): void
    {
        $company = Company::query()->findOrFail(1);

        $result = $this->classifier()->classify($company, Carbon::parse('2026-01-05'), BigDecimal::of('1000000.0000'));

        $this->assertSame(RecalculationRiskClassification::NormalRisk, $result);
    }
}
