<?php

declare(strict_types=1);

namespace App\Modules\Administration;

use App\Modules\Administration\Support\PermissionVocabulary;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AdministrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(PermissionRegistry::class, fn (PermissionRegistry $registry) => $registry->registerVocabulary('administration', PermissionVocabulary::all()));
    }

    public function boot(): void
    {
        Route::middleware(['api', 'auth:sanctum'])->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
