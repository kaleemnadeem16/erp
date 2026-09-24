<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Database\Seeders\Demo\Estimating\ApplicationRatesDemoSeeder;
use Database\Seeders\Demo\Estimating\AssembliesDemoSeeder;
use Database\Seeders\Demo\Estimating\FormulationsDemoSeeder;
use Database\Seeders\Demo\Estimating\RateDimensionsDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoDatabaseSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        DB::transaction(function (): void {
            $this->call([
                FoundationDemoSeeder::class,
                AccountingDemoSeeder::class,
                PartiesDemoSeeder::class,
                InventoryDemoSeeder::class,
                PurchasingDemoSeeder::class,
                StockDemoSeeder::class,
                RateDimensionsDemoSeeder::class,
                ApplicationRatesDemoSeeder::class,
                FormulationsDemoSeeder::class,
                AssembliesDemoSeeder::class,
            ]);
        });
    }
}
