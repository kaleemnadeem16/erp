<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Models\User;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Tax\Support\Quotation\QuotationTaxContext;
use App\Modules\Tax\Support\Quotation\QuotationTaxContextAuthorizer;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sales' proof, for the Tax module, that a tax context is one it would itself build for a real line: the actor is a current ACTIVE member holding the preparer or the issuer duty
 * (each judged by its own scope, so STAGED, disabled or restricted is refused), and the context names exactly this company, a line of a DRAFT revision of this company, that
 * revision's recipient, currency and tax-estimate date, and the very jurisdiction and classification Sales' own facts give for that line. A client-supplied Party, profile,
 * jurisdiction or classification therefore cannot reach the Tax determination. Every denial is the same fixed message and says nothing about which part failed.
 */
final class SalesQuotationTaxContextAuthorizer implements QuotationTaxContextAuthorizer
{
    public function __construct(private readonly QuotationTaxFacts $facts, private readonly CompanyContext $company) {}

    public function authorise(User $actor, QuotationTaxContext $context): void
    {
        $current = ActiveActor::current($actor);
        if ($current === null) {
            throw $this->denied();
        }
        if ($context->companyId !== $this->company->id() || preg_match('/^line:(\d+)$/', $context->lineReference, $match) !== 1) {
            throw $this->denied();
        }
        $line = QuotationDraftLine::query()->find((int) $match[1]);
        $revision = $line === null ? null : QuotationRevision::query()->find($line->revision_id);
        if ($line === null || $revision === null || ! $revision->isDraft() || $revision->recipient_party_id !== $context->partyId || $revision->currency_id !== $context->currencyId
            || $revision->tax_estimate_date?->format('Y-m-d') !== $context->taxDate) {
            throw $this->denied();
        }
        // The preparer or the issuer duty, each judged by the quotation-family policy for THIS revision (QuotationAccess: OWN = its current responsible owner,
        // ALL = the company), the same policy the preview and issue Actions apply; a company-wide check would refuse every OWN holder of their own quotation.
        if (! $this->reaches($current, $revision->id, PermissionVocabulary::QUOTATION_PREPARE) && ! $this->reaches($current, $revision->id, PermissionVocabulary::QUOTATION_ISSUE)) {
            throw $this->denied();
        }
        $classification = $this->facts->classification($revision, $line);
        if ($classification === null || $this->facts->jurisdiction($context->taxDate) !== $context->jurisdiction
            || [$classification->source, $classification->nature, $classification->code, $classification->provenance]
            !== [$context->classification->source, $context->classification->nature, $context->classification->code, $context->classification->provenance]) {
            throw $this->denied();
        }
    }

    private function denied(): AuthorizationException
    {
        return new AuthorizationException('Quotation tax context is not available to this actor.');
    }

    /** Inside the caller's transaction this is a savepoint and the company access fence stays held until the caller commits. Any refusal is a denial. */
    private function reaches(User $actor, int $revisionId, string $duty): bool
    {
        try {
            DB::transaction(fn () => app(QuotationAccess::class)->requireRevision($actor, $revisionId, [$duty]));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
