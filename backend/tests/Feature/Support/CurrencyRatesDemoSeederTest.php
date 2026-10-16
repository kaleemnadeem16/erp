<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyRatesDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_has_zero_two_and_three_place_currencies_dated_rates_and_reruns_change_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $places = Currency::query()->whereIn('code', ['PKR', 'USD', 'JPY', 'KWD', 'CLF'])->pluck('decimal_places', 'code')->all();
        $this->assertEquals(['CLF' => 4, 'JPY' => 0, 'KWD' => 3, 'PKR' => 2, 'USD' => 2], $places);
        $this->assertSame('Rs', Currency::query()->where('code', 'PKR')->value('symbol'));
        $this->assertSame(Currency::query()->where('code', 'PKR')->value('id'), Company::query()->findOrFail(1)->base_currency_id);

        $ids = Currency::query()->pluck('id', 'code');
        $rate = fn (string $from, string $to, string $date, string $type = 'SPOT') => ExchangeRate::query()->where('from_currency_id', $ids[$from])
            ->where('to_currency_id', $ids[$to])->whereDate('rate_date', $date)->where('rate_type', $type)->value('rate');

        $this->assertSame('280.400000000', $rate('USD', 'PKR', '2026-10-05'));
        $this->assertNull($rate('USD', 'PKR', '2026-10-07'), 'a gap: the 7th has no rate, so a prior-date policy would read the 5th');
        $this->assertSame('912.345678901', $rate('KWD', 'PKR', '2026-10-01'), 'a full nine-place rate is kept exactly');
        $this->assertSame('0.917654321', $rate('USD', 'EUR', '2026-10-01'), 'a cross rate that does not go through the base');
        $this->assertSame('279.850000000', $rate('USD', 'PKR', '2026-09-30', 'AVERAGE'));
        $this->assertSame('280.050000000', $rate('USD', 'PKR', '2026-09-30', 'CLOSING'));
        $this->assertGreaterThanOrEqual(13, ExchangeRate::query()->count());
        $this->assertSame('manual', ExchangeRate::query()->value('source'));

        // A hand correction and a governed company edit both survive a rerun; nothing is added.
        $count = ExchangeRate::query()->count();
        ExchangeRate::query()->where('from_currency_id', $ids['USD'])->whereDate('rate_date', '2026-10-05')->update(['rate' => '281.000000000']);
        Company::query()->findOrFail(1)->update(['name' => 'Renamed By A Person']);

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($count, ExchangeRate::query()->count());
        $this->assertSame('281.000000000', $rate('USD', 'PKR', '2026-10-05'));
        $this->assertSame('Renamed By A Person', Company::query()->findOrFail(1)->name);
    }
}
