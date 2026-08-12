<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers auth's own routes, the same way a business module or plugin registers
 * its own routes/migrations/bindings without editing a central file — see
 * docs/architecture/extensibility.md ("a plugin's service provider registers its
 * own routes... without touching core") and docs/architecture/code-structure.md.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Laravel's own routes/api.php only gets the 'api' middleware group and
        // '/api' prefix because withRouting() wraps that specific file — a route file
        // registered from a provider's boot() doesn't inherit that automatically, so
        // it's replicated here explicitly (bootstrap/app.php's apiPrefix default).
        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/auth.php'));
    }
}
