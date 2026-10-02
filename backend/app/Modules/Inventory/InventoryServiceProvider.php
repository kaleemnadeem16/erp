<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Imports\Support\ImportTargetRegistry;
use App\Modules\Inventory\Imports\OpeningStateImportTarget;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\PermissionVocabulary;
use App\Support\CompanyLocks\ExistsProbe;
use App\Support\CompanyLocks\FinancialActivity;
use App\Support\PermissionRegistry;
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
        $this->app->afterResolving(ImportTargetRegistry::class, static function (ImportTargetRegistry $registry): void {
            $registry->register(new OpeningStateImportTarget);
        });

        // Every stock movement is priced in the base currency (ADR 0012), and a cutover posts opening stock in it.
        $this->app->afterResolving(FinancialActivity::class, static function (FinancialActivity $activity): void {
            $activity->register(new ExistsProbe('stock movements', fn (): bool => StockMove::query()->exists()));
            $activity->register(new ExistsProbe('inventory cutovers', fn (): bool => InventoryCutover::query()->exists()));
        });

        $this->app->afterResolving(PermissionRegistry::class, static function (PermissionRegistry $registry): void {
            $registry->registerVocabulary('inventory', PermissionVocabulary::all());
        });
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
