<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;

/**
 * Everything a preparer needs to reopen a draft in a form and write it back: the header fields and the lines exactly as `UpdateQuotationDraft` accepts them
 * (including the writable `scope_ref` and `choice_group_ref` strings), at the input generation to send as `expected_generation`. It is the preparer's duty
 * (`sales.quotation.prepare`, STAGED), independent of `sales.quotation.view`, and it works for an incomplete draft: no recipient, price or financial preview is
 * needed. It carries only what the preparer entered — no computed settlement, FX evidence, base-currency amount, cost, tax or control — and only DRAFT revisions
 * can be reopened. A foreign or unknown revision is the same not-found as everywhere else.
 */
final class ReadQuotationDraftForEdit
{
    public function __invoke(User $actor, int $revisionId): QuotationRevision
    {
        SalesAccess::require($actor, PermissionVocabulary::QUOTATION_PREPARE, 'edit a quotation');
        $revision = QuotationRevision::query()->with(['family', 'lines' => fn ($lines) => $lines->orderBy('sort_order')->orderBy('id'), 'lines.logicalScope', 'lines.choiceGroup'])->find($revisionId)
            ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'revision_id');
        if (! $revision->isDraft()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can be edited.', 'revision_id');
        }

        return $revision;
    }
}
