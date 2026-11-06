<?php

declare(strict_types=1);

namespace App\Modules\Company;

use App\Modules\Company\Support\Brand\CompanyBrandFileOwner;
use App\Modules\Company\Support\CompanyDutyManifest;
use App\Modules\Company\Support\PermissionVocabulary;
use App\Modules\Files\Support\FileOwnerRegistry;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CompanyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(PermissionRegistry::class, function (PermissionRegistry $registry): void {
            $registry->registerVocabulary('company', PermissionVocabulary::all());
            $this->app->make(CompanyDutyManifest::class)->registerWith($registry);
        });
        $this->app->singleton(CompanyDutyManifest::class);
        $this->app->afterResolving(FileOwnerRegistry::class, static function (FileOwnerRegistry $registry): void {
            $registry->register(new CompanyBrandFileOwner);
        });
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
