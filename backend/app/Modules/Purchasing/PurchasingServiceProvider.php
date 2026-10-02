<?php

declare(strict_types=1);

namespace App\Modules\Purchasing;

use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Imports\Support\ImportTargetRegistry;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Purchasing\Imports\OpenPurchaseOrderImportTarget;
use App\Modules\Purchasing\Numbering\GoodsReceiptNumbering;
use App\Modules\Purchasing\Numbering\PurchaseOrderNumbering;
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

        // Purchase orders and goods receipts are numbered by the central framework (ADR 0079); this is all they declare.
        $this->app->afterResolving(NumberingRegistry::class, static function (NumberingRegistry $registry): void {
            $registry->register(new PurchaseOrderNumbering);
            $registry->register(new GoodsReceiptNumbering);
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
