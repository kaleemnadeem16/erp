<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Tax\Actions\CalculateQuotationTax;
use App\Modules\Tax\Actions\DetermineQuotationTaxContext;
use App\Modules\Tax\Actions\RequireCurrentQuotationTaxEvidence;
use App\Modules\Tax\Support\Quotation\QuotationTaxContext;
use App\Modules\Tax\Support\Quotation\QuotationTaxDetermination;
use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxSupport;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The Sales adapter over the Tax module's FR3 (T1 determination, T2 BASIC calculation), written against its public Actions only. For each line it builds the Tax context from
 * the revision (recipient Party, quotation currency, tax-estimate date, `line:{id}`) and the jurisdiction and classification Sales' facts give, asks Tax to determine it (Tax calls
 * `SalesQuotationTaxContextAuthorizer` to prove the context), calculates the tax on the line's settled net as an exclusive NET_FIXED percentage, and re-checks that the evidence
 * is still current. Anything Tax cannot establish — no jurisdiction, no classification, an unknown, unsupported, expired or uncovered context, a net that is not known yet, evidence
 * that moved — is a blocker with a fixed code, never a zero. COMPLETE only when every line is determined and calculated under one Tax generation.
 *
 * Known gap: Tax exposes no lock helper, so the freshness re-check is a second read, not a held fence; the Tax generation it was read at is frozen with the issue so staleness is
 * provable afterwards. When Tax delivers the issue-time fence, this is where it is taken (inside the issue transaction, after the revision lock).
 */
final class Fr3QuotationTaxProvider implements QuotationTaxProvider
{
    public function __construct(
        private readonly QuotationTaxFacts $facts,
        private readonly DetermineQuotationTaxContext $determine,
        private readonly CalculateQuotationTax $calculate,
        private readonly RequireCurrentQuotationTaxEvidence $requireCurrent,
    ) {}

    public function assess(SalesActorContext $actor, QuotationRevision $revision, array $settledNets): QuotationTaxOutcome
    {
        $taxDate = $revision->tax_estimate_date?->format('Y-m-d');
        if ($taxDate === null) {
            return QuotationTaxOutcome::unknown([new IssueBlocker('TAX_ESTIMATE_DATE_REQUIRED', IssueBlocker::TAX)]);
        }
        $jurisdiction = $this->facts->jurisdiction($taxDate);
        if ($jurisdiction === null) {
            return QuotationTaxOutcome::unknown([new IssueBlocker('TAX_JURISDICTION_UNKNOWN', IssueBlocker::TAX)]);
        }
        $lines = $revision->lines()->get()->keyBy('id');
        $blockers = [];
        $results = [];
        $references = [];
        $generations = [];
        foreach ($settledNets as $lineId => $net) {
            $line = $lines->get($lineId);
            if ($line === null) {
                continue;
            }
            $classification = $this->facts->classification($revision, $line);
            if ($classification === null) {
                $blockers[] = new IssueBlocker('TAX_CLASSIFICATION_REQUIRED', IssueBlocker::TAX, $lineId);

                continue;
            }
            if ($net === null) {
                $blockers[] = new IssueBlocker('TAX_NET_UNKNOWN', IssueBlocker::TAX, $lineId);

                continue;
            }
            $context = new QuotationTaxContext($revision->company_id, (int) $revision->recipient_party_id, $revision->currency_id, 'line:'.$lineId, $jurisdiction, $taxDate, $classification);
            $outcome = $this->line($actor, $context, $net, $lineId);
            if ($outcome instanceof IssueBlocker) {
                $blockers[] = $outcome;

                continue;
            }
            [$lineTax, $determination] = $outcome;
            $results[$lineId] = $lineTax;
            $references[$lineId] = $lineTax->evidenceReference;
            $generations[(int) $determination->internalEvidence()['tax_generation']] = true;
        }
        if ($blockers === [] && count($generations) > 1) {
            $blockers[] = new IssueBlocker('TAX_GENERATION_MOVED', IssueBlocker::TAX);
        }
        if ($blockers !== [] || $results === []) {
            return QuotationTaxOutcome::unknown($blockers === [] ? [new IssueBlocker('TAX_NOT_DETERMINED', IssueBlocker::TAX)] : $blockers);
        }
        ksort($references);

        return QuotationTaxOutcome::complete($results, 'FR3.1', (int) array_key_first($generations), hash('sha256', json_encode($references, JSON_THROW_ON_ERROR)));
    }

    /** @return IssueBlocker|array{0: QuotationLineTax, 1: QuotationTaxDetermination} */
    private function line(SalesActorContext $actor, QuotationTaxContext $context, string $net, int $lineId): IssueBlocker|array
    {
        try {
            $determination = ($this->determine)($actor->actor, $context);
            if ($determination->disposition === TaxDisposition::Unknown) {
                return new IssueBlocker($determination->support === TaxSupport::Unsupported ? 'TAX_CALCULATION_UNSUPPORTED' : 'TAX_CONTEXT_UNKNOWN', IssueBlocker::TAX, $lineId);
            }
            $evidence = ($this->calculate)($determination, $net, 'NET_FIXED')->internalEvidence();
            ($this->requireCurrent)($actor->actor, $context, $determination);
        } catch (AuthorizationException) {
            return new IssueBlocker('TAX_CONTEXT_NOT_AUTHORISED', IssueBlocker::TAX, $lineId);
        } catch (DomainInvariantException $refusal) {
            return new IssueBlocker($this->safeCode($refusal->reasonCode), IssueBlocker::TAX, $lineId);
        }
        if (! is_string($evidence['settled_tax'] ?? null) || ! is_string($evidence['gross'] ?? null) || ! is_string($evidence['disposition'] ?? null)) {
            return new IssueBlocker('TAX_CONTEXT_UNKNOWN', IssueBlocker::TAX, $lineId);
        }

        return [new QuotationLineTax($lineId, $evidence['disposition'], $evidence['settled_tax'], $evidence['gross'], $determination->fingerprint()), $determination];
    }

    /** Tax refusals carry stable upper-case codes; anything else (or nothing) becomes the generic unknown, so no free text can reach a blocker. */
    private function safeCode(?string $code): string
    {
        return $code !== null && preg_match('/^TAX_[A-Z0-9_]{3,60}$/', $code) === 1 ? $code : 'TAX_CONTEXT_UNKNOWN';
    }
}
