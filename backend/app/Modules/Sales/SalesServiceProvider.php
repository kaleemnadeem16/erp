<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Models\User;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Parties\Support\PartyCandidateAccess;
use App\Modules\Sales\Numbering\LeadNumbering;
use App\Modules\Sales\Numbering\OpportunityNumbering;
use App\Modules\Sales\Numbering\QuotationNumbering;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Documents\QuotationDocumentProvider;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SalesDutyManifest::class, static fn (): SalesDutyManifest => SalesDutyManifest::shipped());
        $this->app->afterResolving(PermissionRegistry::class, function (PermissionRegistry $registry): void {
            $this->app->make(SalesDutyManifest::class)->registerWith($registry);
        });
        // The quotation customer document type is registered so it can be bound; registration is not permission (ADR 0137).
        $this->app->afterResolving(DocumentTypeRegistry::class, static function (DocumentTypeRegistry $registry): void {
            $registry->registerProvider(new QuotationDocumentProvider);
        });
        $this->app->afterResolving(NumberingRegistry::class, static function (NumberingRegistry $registry): void {
            $registry->register(new LeadNumbering);
            $registry->register(new OpportunityNumbering);
            $registry->register(new QuotationNumbering);
        });
    }

    public function boot(): void
    {
        // People who record enquiries and deals choose or create the Party they are about, so they may ask whether it already exists (ADR 0105).
        PartyCandidateAccess::allow(PermissionVocabulary::LEAD_MANAGE, fn (User $user): bool => SalesAccess::may($user, PermissionVocabulary::LEAD_MANAGE));
        PartyCandidateAccess::allow(PermissionVocabulary::OPPORTUNITY_MANAGE, fn (User $user): bool => SalesAccess::may($user, PermissionVocabulary::OPPORTUNITY_MANAGE));
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
