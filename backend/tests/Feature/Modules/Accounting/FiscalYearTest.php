<?php

namespace Tests\Feature\Modules\Accounting;

use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class FiscalYearTest extends TestCase
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

    public function test_name_is_unique_per_company(): void
    {
        FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

        $this->expectException(QueryException::class);

        FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30']);
    }

    public function test_it_is_company_scoped(): void
    {
        FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

        $this->assertSame(1, FiscalYear::query()->first()->company_id);
    }

    public function test_status_defaults_to_open_and_dates_cast_to_carbon(): void
    {
        $fiscalYear = FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

        $this->assertSame(PeriodStatus::Open, $fiscalYear->fresh()->status);
        $this->assertTrue($fiscalYear->fresh()->starts_on->isSameDay('2026-07-01'));
    }

    public function test_starts_on_must_not_be_after_ends_on(): void
    {
        $this->expectException(QueryException::class);

        FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2027-06-30', 'ends_on' => '2026-07-01']);
    }

    public function test_overlapping_fiscal_years_for_the_same_company_are_rejected(): void
    {
        FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

        $this->expectException(QueryException::class);

        FiscalYear::create(['name' => 'FY2027 partial', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31']);
    }

    public function test_adjacent_non_overlapping_fiscal_years_are_allowed(): void
    {
        FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);
        $next = FiscalYear::create(['name' => 'FY2027-28', 'starts_on' => '2027-07-01', 'ends_on' => '2028-06-30']);

        $this->assertNotNull($next->id);
    }

    public function test_overlapping_fiscal_years_for_different_companies_are_allowed(): void
    {
        FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyFiscalYear = FiscalYear::create(['name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30']);

        $this->assertNotNull($otherCompanyFiscalYear->id);
    }

    public function test_dates_cannot_be_changed_to_exclude_an_existing_period(): void
    {
        $fiscalYear = FiscalYear::create([
            'name' => 'FY2026-27', 'starts_on' => '2026-07-01', 'ends_on' => '2027-06-30',
        ]);
        $fiscalYear->periods()->create([
            'period_no' => 1,
            'name' => 'Jul 2026',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-31',
        ]);

        $this->expectException(RuntimeException::class);

        $fiscalYear->update(['starts_on' => '2026-08-01']);
    }
}
