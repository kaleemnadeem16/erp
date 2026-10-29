<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Models\User;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Estimating\Support\CostEvidenceAuthorities;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Parties\Support\PartyCandidateAccess;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Numbering\LeadNumbering;
use App\Modules\Sales\Numbering\OpportunityNumbering;
use App\Modules\Sales\Numbering\QuotationNumbering;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Documents\QuotationDocumentProvider;
use App\Modules\Sales\Support\Financial\Controls\AssemblyCostEvidenceProvider;
use App\Modules\Sales\Support\Financial\Controls\EstimatingAssemblyCostEvidence;
use App\Modules\Sales\Support\Financial\Controls\QuotationControlEvaluationAuthority;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\Fr3QuotationTaxProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxFacts;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use App\Modules\Sales\Support\Quotation\Issue\SalesQuotationTaxContextAuthorizer;
use App\Modules\Sales\Support\Quotation\Issue\UnavailableQuotationTaxFacts;
use App\Modules\Sales\Support\Quotation\Issue\UnconfiguredQuotationControlsProvider;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Tax\Support\Quotation\QuotationTaxContextAuthorizer;
use App\Support\CompanyLocks\ExistsProbe;
use App\Support\CompanyLocks\FinancialActivity;
use App\Support\PermissionRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SalesDutyManifest::class, static fn (): SalesDutyManifest => SalesDevPreview::manifest());
        // Issue asks two independent evidence questions. Until the Tax-owned FR3 determination (through a Sales adapter) and a configured control set exist, both answer "not yet".
        $this->app->bind(QuotationTaxProvider::class, Fr3QuotationTaxProvider::class);
        // The jurisdiction and classification are not persisted anywhere yet, so the shipped facts say "unknown" and every line stays blocked until an owner-reviewed source is bound.
        $this->app->bind(QuotationTaxFacts::class, UnavailableQuotationTaxFacts::class);
        $this->app->bind(QuotationControlsProvider::class, UnconfiguredQuotationControlsProvider::class);
        $this->app->bind(AssemblyCostEvidenceProvider::class, EstimatingAssemblyCostEvidence::class);
        // Estimating opens cost evidence only for the authority the owning workflow registers: this is Sales' (active member + preparer duty, judged now).
        $this->app->afterResolving(CostEvidenceAuthorities::class, static function (CostEvidenceAuthorities $authorities): void {
            $authorities->register(new QuotationControlEvaluationAuthority);
        });
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
        // A draft line with an entered price carries a pinned base-currency amount, so it locks the company's base currency (invariant 10). A draft with no price holds no money.
        $this->app->afterResolving(FinancialActivity::class, static function (FinancialActivity $activity): void {
            $activity->register(new ExistsProbe('quotation draft prices', fn (): bool => QuotationDraftLine::query()->whereNotNull('unit_price')->exists()));
        });
    }

    public function boot(): void
    {
        // Tax asks a server-owned consumer to prove a tax context; Sales is that consumer (bound after every register(), replacing Tax's refuse-everything default).
        $this->app->bind(QuotationTaxContextAuthorizer::class, SalesQuotationTaxContextAuthorizer::class);
        // People who record enquiries and deals choose or create the Party they are about, so they may ask whether it already exists (ADR 0105).
        PartyCandidateAccess::allow(PermissionVocabulary::LEAD_MANAGE, fn (User $user): bool => SalesAccess::may($user, PermissionVocabulary::LEAD_MANAGE));
        PartyCandidateAccess::allow(PermissionVocabulary::OPPORTUNITY_MANAGE, fn (User $user): bool => SalesAccess::may($user, PermissionVocabulary::OPPORTUNITY_MANAGE));
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
    }
}
