<?php

declare(strict_types=1);

namespace App\Modules\Approvals;

use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Approvals\Support\PermissionVocabulary;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ApprovalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ApprovalSubjectRegistry::class);
        $this->app->afterResolving(PermissionRegistry::class, static function (PermissionRegistry $registry): void {
            $registry->registerVocabulary('approvals', PermissionVocabulary::all());
        });
    }

    public function boot(): void
    {
        if (is_file(__DIR__.'/routes.php')) {
            Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
        }
    }
}
