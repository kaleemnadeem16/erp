<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Models\CustomerAcceptanceLine;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\ServiceCompletionBasis;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal as R;
use App\Support\CompanyContext;

/**
 * Resolves genuine acceptance -> accepted line -> issued line -> the HEAD of its completion-basis chain. Instructions, a quotation line description alone or a local
 * checkbox can never stand in for customer-agreed conditions: with no recorded basis the answer is "none" and a release refuses.
 */
final class SalesCompletionBasis
{
    public function find(CustomerAcceptanceLine $line): ?ResolvedCompletionBasis
    {
        $basis = ServiceCompletionBasis::query()->where('issued_line_id', $line->issued_line_id)->orderByDesc('version')->first();
        if ($basis === null) {
            return null;
        }
        $issued = QuotationIssuedLine::query()->findOrFail($line->issued_line_id);

        return new ResolvedCompletionBasis($basis->id, app(CompanyContext::class)->id(), $issued->id, $basis->version, (string) $issued->description, $basis->agreed_text, $basis->evidence_id, $basis->attestation_policy,
            (string) $basis->terms_source, (string) $basis->materials_scope, (string) $basis->subcontract_scope);
    }

    /** @throws DomainInvariantException COMPLETION_BASIS_MISSING when no agreed conditions were recorded */
    public function resolve(CustomerAcceptanceLine $line): ResolvedCompletionBasis
    {
        return $this->find($line) ?? throw R::make(R::COMPLETION_BASIS_MISSING, 'No customer-agreed completion conditions have been recorded for this accepted service.');
    }
}
