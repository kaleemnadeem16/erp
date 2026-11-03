<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Eagerly materialise the editable draft under current view and prepare eligibility. */
final class ReadQuotationDraftForEdit
{
    public function __invoke(User $actor, int $revisionId): QuotationRevision
    {
        return DB::transaction(function () use ($actor, $revisionId): QuotationRevision {
            $revision = app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE]);
            $revision->load(['family', 'lines' => fn ($lines) => $lines->orderBy('sort_order')->orderBy('id'), 'lines.logicalScope', 'lines.choiceGroup']);
            if (! $revision->isDraft()) {
                throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can be edited.', 'revision_id');
            }
            if (! SalesAccess::mayRead(ActiveActor::require($actor, 'edit a quotation'))) {
                $revision->family->setAttribute('current_opportunity_id', null);
            }

            $document = DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id)->first();
            $revision->setAttribute('document_discount', $document === null ? null : [
                'kind' => (string) $document->kind, 'value' => (string) BigDecimal::of((string) $document->value)->toScale(4),
                'approval_required' => (bool) $document->approval_required, 'approval_reason' => $document->approval_reason,
            ]);

            return $revision;
        });
    }
}
