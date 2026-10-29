<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Models\User;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Tax\Support\Quotation\QuotationTaxContext;
use App\Modules\Tax\Support\Quotation\QuotationTaxContextAuthorizer;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;

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
        if ($current === null || ! (SalesAccess::may($current, PermissionVocabulary::QUOTATION_PREPARE) || SalesAccess::may($current, PermissionVocabulary::QUOTATION_ISSUE))) {
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
}
