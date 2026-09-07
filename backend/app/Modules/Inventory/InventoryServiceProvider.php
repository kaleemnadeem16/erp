<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Modules\Imports\Support\ImportTargetRegistry;
use App\Modules\Inventory\Imports\OpeningStateImportTarget;
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
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
