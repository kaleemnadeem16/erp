<?php

declare(strict_types=1);

namespace App\Modules\Purchasing;

use App\Modules\Imports\Support\ImportTargetRegistry;
use App\Modules\Purchasing\Imports\OpenPurchaseOrderImportTarget;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ImportTargetRegistry::class, static function (ImportTargetRegistry $registry): void {
            $registry->register(new OpenPurchaseOrderImportTarget);
        });
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
