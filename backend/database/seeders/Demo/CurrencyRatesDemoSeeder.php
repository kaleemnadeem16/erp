<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\ManageExchangeRate;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\RateType;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dated manual exchange rates against the demo base currency (PKR), entered through the guarded Currency Action by the demo login (which StockDemoSeeder
 * has already given every capability). Values are illustrative demo figures, not market data. Existing rates are never overwritten, so a rerun adds
 * nothing and a hand-corrected rate survives.
 *
 * What the series is shaped to show:
 *  - USD→PKR has rates on 1, 5, 8 and 12 October and none on 6, 7, 9–11: an exact date, a prior-date fallback (the 7th reads the 5th) and a gap.
 *  - EUR, AED, JPY and KWD→PKR show a zero-place, a three-place and an ordinary currency against the same base.
 *  - JPY and KWD carry full nine-place rates, and USD→EUR is a cross rate that does not go through the base.
 *  - One AVERAGE and one CLOSING USD→PKR rate show that the rate type is part of the key.
 */
final class CurrencyRatesDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    /** @var list<array{string, string, string, string, RateType}> */
    private const RATES = [
        ['USD', 'PKR', '2026-10-01', '280.250000000', RateType::Spot],
        ['USD', 'PKR', '2026-10-05', '280.400000000', RateType::Spot],
        ['USD', 'PKR', '2026-10-08', '279.900000000', RateType::Spot],
        ['USD', 'PKR', '2026-10-12', '280.100000000', RateType::Spot],
        ['USD', 'PKR', '2026-09-30', '279.850000000', RateType::Average],
        ['USD', 'PKR', '2026-09-30', '280.050000000', RateType::Closing],
        ['EUR', 'PKR', '2026-10-01', '305.432100000', RateType::Spot],
        ['EUR', 'PKR', '2026-10-08', '306.120000000', RateType::Spot],
        ['AED', 'PKR', '2026-10-01', '76.310000000', RateType::Spot],
        ['AED', 'PKR', '2026-10-08', '76.290000000', RateType::Spot],
        ['JPY', 'PKR', '2026-10-01', '1.876543210', RateType::Spot],
        ['KWD', 'PKR', '2026-10-01', '912.345678901', RateType::Spot],
        ['USD', 'EUR', '2026-10-01', '0.917654321', RateType::Spot],
    ];

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $manage = app(ManageExchangeRate::class);
        $ids = Currency::query()->pluck('id', 'code');

        foreach (self::RATES as [$from, $to, $date, $rate, $type]) {
            $exists = ExchangeRate::query()->where('from_currency_id', $ids[$from])->where('to_currency_id', $ids[$to])
                ->whereDate('rate_date', $date)->where('rate_type', $type->value)->exists();
            if ($exists) {
                continue;
            }
            $manage->create($actor, ['from_currency_id' => $ids[$from], 'to_currency_id' => $ids[$to], 'rate' => $rate, 'rate_date' => $date, 'rate_type' => $type->value]);
        }
    }
}
