<?php

namespace Tests\Feature\Modules\Accounting;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AccountingPeriodTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCompany(int $companyId): void
    {
        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
    }

    private function createSecondCompany(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'Other Company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fiscalYear(): FiscalYear
    {
        return FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
    }

    public function test_it_is_company_scoped(): void
    {
        AccountingPeriod::create([
            'fiscal_year_id' => $this->fiscalYear()->id, 'period_no' => 1,
            'name' => 'Jul 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31',
        ]);

        $this->assertSame(1, AccountingPeriod::query()->first()->company_id);
    }

    public function test_status_defaults_to_open(): void
    {
        $period = AccountingPeriod::create([
            'fiscal_year_id' => $this->fiscalYear()->id, 'period_no' => 1,
            'name' => 'Jul 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31',
        ]);

        $this->assertSame(PeriodStatus::Open, $period->fresh()->status);
    }

    public function test_period_no_is_unique_per_fiscal_year(): void
    {
        $fiscalYear = $this->fiscalYear();
        AccountingPeriod::create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 1,
            'name' => 'Jul 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31',
        ]);

        $this->expectException(QueryException::class);

        AccountingPeriod::create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 1,
            'name' => 'Duplicate', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31',
        ]);
    }

    public function test_starts_on_must_not_be_after_ends_on(): void
    {
        $this->expectException(QueryException::class);

        AccountingPeriod::create([
            'fiscal_year_id' => $this->fiscalYear()->id, 'period_no' => 1,
            'name' => 'Jul 2026', 'starts_on' => '2026-07-31', 'ends_on' => '2026-07-01',
        ]);
    }

    public function test_overlapping_periods_for_the_same_company_are_rejected(): void
    {
        $fiscalYear = $this->fiscalYear();
        AccountingPeriod::create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 1,
            'name' => 'Jul 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31',
        ]);

        $this->expectException(QueryException::class);

        AccountingPeriod::create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 2,
            'name' => 'Overlapping', 'starts_on' => '2026-07-15', 'ends_on' => '2026-08-15',
        ]);
    }

    public function test_adjacent_non_overlapping_periods_are_allowed(): void
    {
        $fiscalYear = $this->fiscalYear();
        AccountingPeriod::create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 1,
            'name' => 'Jul 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31',
        ]);
        $august = AccountingPeriod::create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 2,
            'name' => 'Aug 2026', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31',
        ]);

        $this->assertNotNull($august->id);
    }

    public function test_fiscal_year_id_must_resolve_to_a_fiscal_year_that_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a FiscalYear');

        AccountingPeriod::create([
            'fiscal_year_id' => 999999, 'period_no' => 1,
            'name' => 'Jul 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31',
        ]);
    }

    public function test_fiscal_year_id_cannot_point_at_another_companys_fiscal_year(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyFiscalYear = $this->fiscalYear();

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a FiscalYear');

        AccountingPeriod::create([
            'fiscal_year_id' => $otherCompanyFiscalYear->id, 'period_no' => 1,
            'name' => 'Jul 2026', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31',
        ]);
    }

    public function test_a_period_starting_before_its_fiscal_year_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must fall entirely within its fiscal year');

        AccountingPeriod::create([
            'fiscal_year_id' => $this->fiscalYear()->id, 'period_no' => 1,
            'name' => 'Too early', 'starts_on' => '2026-06-15', 'ends_on' => '2026-07-15',
        ]);
    }

    public function test_a_period_ending_after_its_fiscal_year_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must fall entirely within its fiscal year');

        AccountingPeriod::create([
            'fiscal_year_id' => $this->fiscalYear()->id, 'period_no' => 12,
            'name' => 'Too late', 'starts_on' => '2027-06-15', 'ends_on' => '2027-07-15',
        ]);
    }

    public function test_a_period_matching_its_fiscal_years_exact_bounds_is_allowed(): void
    {
        $fiscalYear = $this->fiscalYear();

        $period = AccountingPeriod::create([
            'fiscal_year_id' => $fiscalYear->id, 'period_no' => 1,
            'name' => 'Full year', 'starts_on' => $fiscalYear->starts_on, 'ends_on' => $fiscalYear->ends_on,
        ]);

        $this->assertNotNull($period->id);
    }
}
