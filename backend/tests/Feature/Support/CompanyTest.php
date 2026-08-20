<?php

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiscal_year_start_month_defaults_to_july(): void
    {
        $this->assertSame(7, Company::query()->findOrFail(1)->fiscal_year_start_month);
    }

    public function test_fiscal_year_start_month_must_be_between_1_and_12(): void
    {
        $this->expectException(QueryException::class);

        Company::query()->findOrFail(1)->update(['fiscal_year_start_month' => 13]);
    }

    public function test_base_currency_id_is_nullable_and_resolves_the_relation_when_set(): void
    {
        $usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);

        $company = Company::query()->findOrFail(1);
        $this->assertNull($company->base_currency_id);

        $company->update(['base_currency_id' => $usd->id]);

        $this->assertSame($usd->id, $company->fresh()->baseCurrency->id);
    }
}
