<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Support\Facades\DB;

/** Safe quotation metadata, materialised while current family eligibility is protected. */
final class ReadQuotationDraft
{
    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $revisionId): array
    {
        return DB::transaction(function () use ($actor, $revisionId): array {
            $revision = app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW]);
            $revision->load('family');
            $current = ActiveActor::require($actor, 'view a quotation');

            return [
                'revision_id' => $revision->id, 'family_id' => $revision->family_id, 'revision_ordinal' => $revision->revision_ordinal, 'state' => $revision->state,
                'input_generation' => $revision->input_generation, 'recipient_party_id' => $revision->recipient_party_id, 'currency_id' => $revision->currency_id,
                'pricing_date' => $revision->pricing_date?->format('Y-m-d'), 'tax_estimate_date' => $revision->tax_estimate_date?->format('Y-m-d'),
                'valid_through_local_date' => $revision->valid_through_local_date?->format('Y-m-d'),
                'current_opportunity_id' => SalesAccess::mayRead($current) ? $revision->family->current_opportunity_id : null,
                'line_count' => QuotationDraftLine::query()->where('revision_id', $revision->id)->count(), 'created_by' => $revision->created_by,
            ];
        });
    }
}
