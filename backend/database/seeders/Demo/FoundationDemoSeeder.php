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

        // The company profile and its time zone (ADR 0080): filled only where still empty, so re-seeding never overwrites what
        // someone has set on Settings → Company.
        $company = Company::query()->findOrFail(1);
        $profile = [
            'code' => 'X1', 'legal_name' => 'X1 ERP Demo Company (Pvt) Ltd', 'country_code' => 'PK',
            'address_line1' => '12 Industrial Estate Road', 'city' => 'Lahore', 'state' => 'Punjab', 'postal_code' => '54000',
            'email' => 'info@demo.example', 'phone' => '+92 42 111 000 111', 'website' => 'https://demo.example',
        ];
        foreach ($profile as $field => $value) {
            if ($company->getAttribute($field) === null) {
                $company->setAttribute($field, $value);
            }
        }
        if ($company->timezone === 'UTC') {
            $company->timezone = 'Asia/Karachi';
        }
        $company->save();

        $units = [
            ['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'g', 'name' => 'Gram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '0.001000000', 'is_base' => false],
            ['code' => 'l', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'ml', 'name' => 'Millilitre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '0.001000000', 'is_base' => false],
            ['code' => 'm', 'name' => 'Metre', 'dimension' => UomDimension::Length, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1.000000000', 'is_base' => true],
            ['code' => 'hr', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1.000000000', 'is_base' => true],
            // Pack units are COUNT-dimension unit *names* only. How much of an item's own stock unit
            // one bag/can/drum/roll/box holds is a per-item fact, seeded as pack conversions by
            // PackConversionsDemoSeeder — never a global ratio (ADR 0050).
            ['code' => 'bag', 'name' => 'Bag', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => false],
            ['code' => 'can', 'name' => 'Can', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => false],
            ['code' => 'drum', 'name' => 'Drum', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => false],
            ['code' => 'roll', 'name' => 'Roll', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => false],
            ['code' => 'box', 'name' => 'Box', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => false],
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
