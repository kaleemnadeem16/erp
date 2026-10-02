<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers routes for the currency / exchange-rate cross-cutting mechanism
 * (App\Models\Currency, App\Models\ExchangeRate — neither lives in app/Modules,
 * both built in Phase 0 alongside UoM/custom fields/sequences), the same
 * self-registering pattern AuthServiceProvider and each app/Modules provider
 * use — see docs/architecture/code-structure.md.
 */
class CurrencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(PermissionRegistry::class, fn (PermissionRegistry $registry) => $registry->registerVocabulary('currency', [
            'currency.exchange-rate.manage',
        ]));
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/exchange-rates.php'));
    }
}
