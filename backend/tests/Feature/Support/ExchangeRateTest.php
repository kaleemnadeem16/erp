<?php

namespace Tests\Feature\Support;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Support\RateType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    private Currency $usd;

    private Currency $pkr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usd = Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$']);
        $this->pkr = Currency::create(['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => 'Rs']);
    }

    public function test_rate_type_and_dates_are_cast(): void
    {
        $rate = ExchangeRate::create([
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '280.500000000',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot,
            'source' => 'manual',
        ]);

        $this->assertSame(RateType::Spot, $rate->rate_type);
        $this->assertSame('2026-08-13', $rate->rate_date->toDateString());
    }

    public function test_from_and_to_currency_relations_resolve(): void
    {
        $rate = ExchangeRate::create([
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '280.5',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot,
        ]);

        $this->assertSame('USD', $rate->fromCurrency->code);
        $this->assertSame('PKR', $rate->toCurrency->code);
    }

    public function test_the_same_pair_date_and_type_cannot_be_recorded_twice_for_one_company(): void
    {
        ExchangeRate::create([
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '280.5',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot,
        ]);

        $this->expectException(QueryException::class);

        ExchangeRate::create([
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '281.0',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot,
        ]);
    }

    public function test_a_different_rate_type_on_the_same_date_is_allowed(): void
    {
        ExchangeRate::create([
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '280.5',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot,
        ]);

        $closing = ExchangeRate::create([
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '281.0',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Closing,
        ]);

        $this->assertSame(RateType::Closing, $closing->rate_type);
    }

    public function test_it_is_company_scoped(): void
    {
        ExchangeRate::create([
            'from_currency_id' => $this->usd->id,
            'to_currency_id' => $this->pkr->id,
            'rate' => '280.5',
            'rate_date' => '2026-08-13',
            'rate_type' => RateType::Spot,
        ]);

        $this->assertSame(1, ExchangeRate::query()->first()->company_id);
    }
}
