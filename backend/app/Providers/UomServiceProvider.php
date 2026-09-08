<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers routes for App\Models\Uom — a Phase 0 cross-cutting mechanism
 * that, like Currency/ExchangeRate (see CurrencyServiceProvider), doesn't
 * live in app/Modules. Read-only: see UomController.
 */
class UomServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/uoms.php'));
    }
}
