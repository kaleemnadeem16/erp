<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Database\Seeders\Demo\Estimating\ApplicationRatesDemoSeeder;
use Database\Seeders\Demo\Estimating\AssembliesDemoSeeder;
use Database\Seeders\Demo\Estimating\AssemblyVersionsDemoSeeder;
use Database\Seeders\Demo\Estimating\FormulationsDemoSeeder;
use Database\Seeders\Demo\Estimating\MaterialSelectionDemoSeeder;
use Database\Seeders\Demo\Estimating\MaterialVarianceDemoSeeder;
use Database\Seeders\Demo\Estimating\OverheadPoliciesDemoSeeder;
use Database\Seeders\Demo\Estimating\RateDimensionsDemoSeeder;
use Database\Seeders\Demo\Estimating\ResourceRatesDemoSeeder;
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
                // Before Purchasing: some item-supplier rows buy in a pack unit.
                PackConversionsDemoSeeder::class,
                PurchasingDemoSeeder::class,
                StockDemoSeeder::class,
                // After StockDemoSeeder: reuses its demo-ops/demo-supervisor actors and resting policy.
                NegativeStockLifecycleDemoSeeder::class,
                RecalculationLifecycleDemoSeeder::class,
                StockTransfersDemoSeeder::class,
                // After the stock seeders: it switches the company to STRUCTURED and stocks the pigment in typed locations.
                StructuredLocationsDemoSeeder::class,
                RateDimensionsDemoSeeder::class,
                ApplicationRatesDemoSeeder::class,
                FormulationsDemoSeeder::class,
                AssembliesDemoSeeder::class,
                OverheadPoliciesDemoSeeder::class,
                ResourceRatesDemoSeeder::class,
                AssemblyVersionsDemoSeeder::class,
                MaterialVarianceDemoSeeder::class,
                MaterialSelectionDemoSeeder::class,
                // Last: it uses the demo supplier and pigment, the demo variance rules and the personas' permissions.
                ApprovalsDemoSeeder::class,
            ]);
        });
    }
}
