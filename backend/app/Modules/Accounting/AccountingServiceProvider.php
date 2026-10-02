<?php

declare(strict_types=1);

namespace App\Modules\Accounting;

use App\Modules\Accounting\Support\AccountingFiscalCalendar;
use App\Modules\Accounting\Support\PermissionVocabulary;
use App\Modules\Numbering\Support\FiscalCalendar;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Accounting's own routes, per docs/architecture/code-structure.md —
 * each module owns a routes.php and registers it from its own provider's
 * boot(), rather than a central routes/api.php growing one section per module.
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The numbering framework asks the fiscal calendar a question through a port; the ledger answers it.
        $this->app->bind(FiscalCalendar::class, AccountingFiscalCalendar::class);
        $this->app->afterResolving(PermissionRegistry::class, fn (PermissionRegistry $registry) => $registry->registerVocabulary('accounting', PermissionVocabulary::all()));
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
