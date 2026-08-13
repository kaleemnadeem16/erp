<?php

namespace Tests\Feature\Support;

use App\Models\Currency;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_globally_unique(): void
    {
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);

        $this->expectException(QueryException::class);

        Currency::create(['code' => 'USD', 'name' => 'Duplicate', 'symbol' => '$']);
    }

    public function test_decimal_places_defaults_to_two(): void
    {
        $currency = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);

        $this->assertSame(2, $currency->fresh()->decimal_places);
    }

    public function test_a_currency_can_have_zero_decimal_places(): void
    {
        $currency = Currency::create(['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimal_places' => 0]);

        $this->assertSame(0, $currency->decimal_places);
    }

    public function test_it_is_visible_regardless_of_the_current_company(): void
    {
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);

        $this->app->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });

        $this->assertSame(1, Currency::query()->count());
    }
}
