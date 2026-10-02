<?php

declare(strict_types=1);

namespace App\Modules\Purchasing;

use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Imports\Support\ImportTargetRegistry;
use App\Modules\Purchasing\Imports\OpenPurchaseOrderImportTarget;
use App\Modules\Purchasing\Support\PermissionVocabulary;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ImportTargetRegistry::class, static function (ImportTargetRegistry $registry): void {
            $registry->register(new OpenPurchaseOrderImportTarget);
        });

        $this->app->afterResolving(ApprovalSubjectRegistry::class, static function (ApprovalSubjectRegistry $registry): void {
            $registry->register(new PurchaseOrderApprovalSubject);
        });

        $this->app->afterResolving(PermissionRegistry::class, static function (PermissionRegistry $registry): void {
            $registry->registerVocabulary('purchasing', PermissionVocabulary::all());
        });
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
