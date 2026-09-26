<?php

declare(strict_types=1);

namespace App\Modules\Estimating;

use App\Modules\Estimating\Support\PermissionVocabulary;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class EstimatingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(PermissionRegistry::class, static function (PermissionRegistry $registry): void {
            $registry->registerVocabulary('estimating', PermissionVocabulary::all());
        });
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
