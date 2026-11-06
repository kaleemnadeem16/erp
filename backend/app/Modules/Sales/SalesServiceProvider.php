<?php

declare(strict_types=1);

namespace App\Modules\Sales;

use App\Models\User;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Estimating\Support\CostEvidenceAuthorities;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Parties\Support\PartyCandidateAccess;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\SalesPriceReference;
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
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use App\Modules\Sales\Support\Quotation\Issue\ConfiguredQuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\DraftQuotationTaxFacts;
use App\Modules\Sales\Support\Quotation\Issue\Fr3QuotationTaxProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxFacts;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use App\Modules\Sales\Support\Quotation\Issue\SalesQuotationTaxContextAuthorizer;
use App\Modules\Sales\Support\Quotation\NoAcceptanceYet;
use App\Modules\Sales\Support\Quotation\QuotationAcceptanceState;
use App\Modules\Sales\Support\Quotation\QuotationFamilyScopeAdapter;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Tax\Support\Quotation\QuotationTaxContextAuthorizer;
use App\Support\CompanyLocks\ExistsProbe;
use App\Support\CompanyLocks\FinancialActivity;
use App\Support\PermissionRegistry;
use App\Support\Scope\ResourceScopeAdapters;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(ResourceScopeAdapters::class, static function (ResourceScopeAdapters $adapters): void {
            $adapters->register(QuotationFamilyScopeAdapter::RESOURCE, new QuotationFamilyScopeAdapter);
        });
        $this->app->singleton(SalesDutyManifest::class, static fn (): SalesDutyManifest => SalesDevPreview::manifest());
        // Issue asks two independent evidence questions. Until the Tax-owned FR3 determination (through a Sales adapter) and a configured control set exist, both answer "not yet".
        $this->app->bind(QuotationTaxProvider::class, Fr3QuotationTaxProvider::class);
        // Jurisdiction and Item classification come from Tax's own append-only facts (none exist until the staged configuration duty is used); a manual line's classification is the
        // seller's declared, versioned fact on the draft. With nothing declared or configured every line stays blocked: nothing is defaulted.
        $this->app->bind(QuotationTaxFacts::class, DraftQuotationTaxFacts::class);
        $this->app->bind(QuotationControlsProvider::class, ConfiguredQuotationControlsProvider::class);
        // B5 rebinds this to the reader of the real acceptance records.
        $this->app->bind(QuotationAcceptanceState::class, NoAcceptanceYet::class);
        $this->app->bind(AssemblyCostEvidenceProvider::class, EstimatingAssemblyCostEvidence::class);
        // Estimating opens cost evidence only for the authority the owning workflow registers: this is Sales' (active member + preparer duty, judged now).
        $this->app->afterResolving(CostEvidenceAuthorities::class, static function (CostEvidenceAuthorities $authorities): void {
            $authorities->register(new QuotationControlEvaluationAuthority);
        });
        // Quotation drafts are an approval subject: the recorded need for approval can be requested and decided through the shared engine (ADR 0158).
        $this->app->afterResolving(ApprovalSubjectRegistry::class, static function (ApprovalSubjectRegistry $registry): void {
            $registry->register(new QuotationApprovalSubject);
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
            $activity->register(new ExistsProbe('governed sales price references', fn (): bool => SalesPriceReference::query()->exists()));
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
