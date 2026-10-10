<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Imports\Support\ImportTargetRegistry;
use App\Modules\Inventory\Actions\Costing\AvcoCostStrategy;
use App\Modules\Inventory\Actions\Costing\FifoCostStrategy;
use App\Modules\Inventory\Actions\Costing\SpecificCostStrategy;
use App\Modules\Inventory\Imports\OpeningStateImportTarget;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Numbering\StockScrapNumbering;
use App\Modules\Inventory\Support\Costing\CostStrategyRegistry;
use App\Modules\Inventory\Support\PermissionVocabulary;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Support\CompanyLocks\ExistsProbe;
use App\Support\CompanyLocks\FinancialActivity;
use App\Support\PermissionRegistry;
use App\Support\Scope\ScopedResourceTypes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Inventory's own routes, per docs/architecture/code-structure.md —
 * each module owns a routes.php and registers it from its own provider's
 * boot(), rather than a central routes/api.php growing one section per module.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CostStrategyRegistry::class, function ($app) {
            $registry = new CostStrategyRegistry;
            foreach ([AvcoCostStrategy::class,
                FifoCostStrategy::class,
                SpecificCostStrategy::class] as $strategy) {
                $registry->register($app->make($strategy));
            }

            return $registry;
        });

        $this->app->afterResolving(ImportTargetRegistry::class, static function (ImportTargetRegistry $registry): void {
            $registry->register(new OpeningStateImportTarget);
        });

        // Every stock movement is priced in the base currency (ADR 0012), and a cutover posts opening stock in it.
        $this->app->afterResolving(FinancialActivity::class, static function (FinancialActivity $activity): void {
            $activity->register(new ExistsProbe('stock movements', fn (): bool => StockMove::query()->exists()));
            $activity->register(new ExistsProbe('inventory cutovers', fn (): bool => InventoryCutover::query()->exists()));
        });

        // A scrap document is numbered by the central framework when it is posted (ADR 0079, ADR 0097).
        $this->app->afterResolving(NumberingRegistry::class, static function (NumberingRegistry $registry): void {
            $registry->register(new StockScrapNumbering);
        });

        $this->app->afterResolving(PermissionRegistry::class, static function (PermissionRegistry $registry): void {
            $registry->registerVocabulary('inventory', PermissionVocabulary::all());
        });

        // A warehouse can belong to an organisation unit, so access can follow it (ADR 0089).
        $this->app->afterResolving(ScopedResourceTypes::class, static function (ScopedResourceTypes $types): void {
            $types->register('warehouse', 'Warehouse', fn (int $id): bool => Warehouse::query()->whereKey($id)->exists(), fn (int $id): ?string => Warehouse::query()->whereKey($id)->value('name'));
        });
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
