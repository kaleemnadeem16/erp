<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Database\Seeders\Demo\Estimating\ApplicationRatesDemoSeeder;
use Database\Seeders\Demo\Estimating\AssembliesDemoSeeder;
use Database\Seeders\Demo\Estimating\AssemblyVersionsDemoSeeder;
use Database\Seeders\Demo\Estimating\CostCertaintyDemoSeeder;
use Database\Seeders\Demo\Estimating\FormulationsDemoSeeder;
use Database\Seeders\Demo\Estimating\MaterialSelectionDemoSeeder;
use Database\Seeders\Demo\Estimating\MaterialVarianceDemoSeeder;
use Database\Seeders\Demo\Estimating\OverheadPoliciesDemoSeeder;
use Database\Seeders\Demo\Estimating\RateDimensionsDemoSeeder;
use Database\Seeders\Demo\Estimating\RecipeAccessDemoSeeder;
use Database\Seeders\Demo\Estimating\ResourceRatesDemoSeeder;
use Database\Seeders\Demo\Documents\DocumentConfigurationDemoSeeder;
use Database\Seeders\Demo\Sales\QuotationDraftsDemoSeeder;
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
                PackagedPurchasingDemoSeeder::class,
                PackageCheckpointDemoSeeder::class,
                PackageTrackingPolicyDemoSeeder::class,
                ReceiptPackageEvidenceDemoSeeder::class,
                PackageOpeningDemoSeeder::class,
                PackageOpeningOperationsDemoSeeder::class,
                PackageIssueDemoSeeder::class,
                PackageTransferDemoSeeder::class,
                PackageActivationDemoSeeder::class,
                StockDemoSeeder::class,
                // After StockDemoSeeder: the rates are entered by the demo login through the guarded Currency Action, once StockDemoSeeder has granted it.
                CurrencyRatesDemoSeeder::class,
                // After StockDemoSeeder: the count is posted by its demo-supervisor persona (the creator may not post).
                PackageCountDemoSeeder::class,
                PackageReceiptReversalDemoSeeder::class,
                PackageQaDemoSeeder::class,
                RejectedStockExitDemoSeeder::class,
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
                CostCertaintyDemoSeeder::class,
                MaterialVarianceDemoSeeder::class,
                MaterialSelectionDemoSeeder::class,
                // After the assemblies and the stock seeders: a FIFO material in two depots, and the company's stock-choice rule.
                StockMixControlDemoSeeder::class,
                // Last: it uses the demo supplier and pigment, the demo variance rules and the personas' permissions.
                ApprovalsDemoSeeder::class,
                // Needs the demo administrator's full permission set (synced by StockDemoSeeder) and the registered recipe capability.
                RecipeAccessDemoSeeder::class,
                // After ApprovalsDemoSeeder: the auditors read the approvals it leaves in every state.
                ApprovalAuditorsDemoSeeder::class,
                // Needs the demo warehouses (InventoryDemoSeeder, StockTransfersDemoSeeder): two sites that own them, and a limited clerk for each.
                OrganisationDemoSeeder::class,
                SalesCrmDemoSeeder::class,
                // After SalesCrmDemoSeeder (a draft links to its deal) and the currency rates; a no-op unless the staged quotation duties are locally activated.
                QuotationDraftsDemoSeeder::class,
                CustomFieldsDemoSeeder::class,
                // Configuration only; independent of the other seeders (needs the demo login).
                DocumentConfigurationDemoSeeder::class,
            ]);
        });
    }
}
