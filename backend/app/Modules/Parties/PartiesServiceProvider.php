<?php

declare(strict_types=1);

namespace App\Modules\Parties;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Parties' own routes, per docs/architecture/code-structure.md —
 * each module owns a routes.php and registers it from its own provider's
 * boot(), rather than a central routes/api.php growing one section per module.
 */
class PartiesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
