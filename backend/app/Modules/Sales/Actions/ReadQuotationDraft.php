<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;

/**
 * Safe metadata of a quotation revision for the people who may see that quotations exist (`sales.quotation.view`, STAGED): identity, state, input generation,
 * recipient, currency, dates, the current CRM association and how many lines it has. It carries no price, quantity, cost or any commercial figure — those are
 * the preparer's workflow (`PreviewQuotation`) or a later commercial-view duty.
 */
final class ReadQuotationDraft
{
    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $revisionId): array
    {
        SalesAccess::require($actor, PermissionVocabulary::QUOTATION_VIEW, 'view a quotation');
        $revision = QuotationRevision::query()->with('family')->find($revisionId)
            ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'revision_id');

        return [
            'revision_id' => $revision->id, 'family_id' => $revision->family_id, 'revision_ordinal' => $revision->revision_ordinal, 'state' => $revision->state,
            'input_generation' => $revision->input_generation, 'recipient_party_id' => $revision->recipient_party_id, 'currency_id' => $revision->currency_id,
            'pricing_date' => $revision->pricing_date?->format('Y-m-d'), 'tax_estimate_date' => $revision->tax_estimate_date?->format('Y-m-d'),
            'valid_through_local_date' => $revision->valid_through_local_date?->format('Y-m-d'), 'current_opportunity_id' => $revision->family->current_opportunity_id,
            'line_count' => QuotationDraftLine::query()->where('revision_id', $revision->id)->count(), 'created_by' => $revision->created_by,
        ];
    }
}
