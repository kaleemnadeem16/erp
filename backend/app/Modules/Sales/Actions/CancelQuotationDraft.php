<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Approvals\Actions\InvalidateApprovals;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;

/**
 * Abandons a draft quotation revision with a reason (QL4): the revision becomes CANCELLED_DRAFT, terminal and immutable, and never held an official number. It touches nothing
 * else — cancelling a successor draft leaves the issued revision it was based on exactly as available as before. Guarded by `sales.quotation.prepare` (STAGED) and a currently
 * active member, judged again inside the operation before any replay; exact-intent replay under a request key; a moved draft is stale.
 */
final class CancelQuotationDraft
{
    public function __construct(private readonly QuotationOperation $operations) {}

    public function __invoke(User $actor, int $revisionId, int $expectedGeneration, ?string $requestKey, string $reason): QuotationDraftOutcome
    {
        $this->authorise($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A reason of up to 1000 characters is required to cancel a draft.', 'reason');
        }
        $context = SalesActorContext::forUser($actor, 'quotation.cancel_draft');

        $outcome = $this->operations->run(
            $context, 'quotation.cancel_draft', $requestKey, ['revision_id' => $revisionId, 'expected_generation' => $expectedGeneration, 'reason' => $reason],
            fn (): QuotationRevision => app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE], true),
            function (QuotationRevision $revision) use ($actor, $expectedGeneration, $reason): array {
                if (! $revision->isDraft()) {
                    throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can be cancelled.', 'revision_id');
                }
                if ($revision->input_generation !== $expectedGeneration) {
                    throw QuotationRefusal::make(QuotationRefusal::QUOTATION_DRAFT_STALE, "The draft is at generation {$revision->input_generation}, not {$expectedGeneration}; review it again before cancelling.", 'expected_generation');
                }
                app(InvalidateApprovals::class)->forSubject(QuotationApprovalSubject::CODE, $revision->id, 'The draft was cancelled.');
                $revision->forceFill(['state' => 'CANCELLED_DRAFT', 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => $reason])->save();

                return QuotationDraftOutcome::result($revision->family_id, $revision->id, $revision->input_generation);
            },
        );

        return QuotationDraftOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    private function authorise(User $actor): void
    {
        // Capability and record access are judged inside the operation by QuotationAccess, under the company access fence; this is only the early refusal of a non-member.
        ActiveActor::require($actor, 'use quotations');
    }
}
