<?php

declare(strict_types=1);

namespace App\Modules\Numbering;

use App\Modules\Numbering\Services\NullFiscalCalendar;
use App\Modules\Numbering\Support\FiscalCalendar;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Numbering\Support\PermissionVocabulary;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The central document-numbering framework (ADR 0079). Modules register their numberable document types against the
 * NumberingRegistry from their own providers; the fiscal calendar is a port the Accounting module fulfils.
 */
class NumberingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NumberingRegistry::class);
        $this->app->bindIf(FiscalCalendar::class, NullFiscalCalendar::class);
        $this->app->afterResolving(PermissionRegistry::class, static function (PermissionRegistry $registry): void {
            $registry->registerVocabulary('numbering', PermissionVocabulary::all());
        });
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
