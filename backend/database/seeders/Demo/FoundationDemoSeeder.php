<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Support\UomDimension;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

final class FoundationDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $currencies = [
            ['code' => 'PKR', 'name' => 'Pakistani Rupee', 'symbol' => 'Rs', 'decimal_places' => 2],
            ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'AED', 'decimal_places' => 2],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->updateOrCreate(['code' => $currency['code']], $currency);
        }

        $pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
        Company::query()->findOrFail(1)->update([
            'name' => 'X1 ERP Demo Company',
            'base_currency_id' => $pkr->getKey(),
        ]);

        $units = [
            ['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'g', 'name' => 'Gram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '0.001000000', 'is_base' => false],
            ['code' => 'l', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'ml', 'name' => 'Millilitre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '0.001000000', 'is_base' => false],
            ['code' => 'm', 'name' => 'Metre', 'dimension' => UomDimension::Length, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'hr', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1.000000000', 'is_base' => true],
        ];

        foreach ($units as $unit) {
            Uom::query()->updateOrCreate(['code' => $unit['code']], $unit);
        }

        $user = User::query()->updateOrCreate(
            ['email' => config('demo.user.email')],
            ['name' => config('demo.user.name'), 'password' => config('demo.user.password')],
        );
        $user->forceFill(['email_verified_at' => now()])->save();
    }
}
