<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\Auth\SignInRequirements;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Registers auth's own routes, the same way a business module or plugin registers
 * its own routes/migrations/bindings without editing a central file — see
 * docs/architecture/extensibility.md ("a plugin's service provider registers its
 * own routes... without touching core") and docs/architecture/code-structure.md.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The checks that stand between a correct password and a signed-in person; empty today (docs/decisions/0088-*.md, Q37).
        $this->app->singleton(SignInRequirements::class);
    }

    public function boot(): void
    {
        // Sanctum's token plus `meta` (how and where a device signed in).
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // An expired token is already refused at the door; deleting the rows is housekeeping, not enforcement (ADR 0088).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('sanctum:prune-expired --hours=24')->daily();
        });

        // Laravel's own routes/api.php only gets the 'api' middleware group and
        // '/api' prefix because withRouting() wraps that specific file — a route file
        // registered from a provider's boot() doesn't inherit that automatically, so
        // it's replicated here explicitly (bootstrap/app.php's apiPrefix default).
        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/auth.php'));
    }
}
