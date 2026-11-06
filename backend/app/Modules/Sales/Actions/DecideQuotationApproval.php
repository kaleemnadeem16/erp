<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Approval\EngineRefusals;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalOutcome;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovals;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;

/**
 * Approves or rejects the pending approval of one draft (ADR 0158). The checker half: `sales.quotation.approve` with the independent view (both STAGED), judged by the
 * quotation-family record policy (OWN or ALL) for THIS revision, 404 when it is not visible - and the Approvals engine's own authority (policy step capability and, when a
 * step uses one, an authority ceiling) is asked as well, so there is one definition of "may decide". Separation of duties is not a policy option here: the person who created
 * the draft, entered any concession on it or requested the approval can never decide it, and a delegate cannot stand in (no delegation until the owner decides). The decision is
 * for the draft exactly as the decider reviewed it: `expected_generation` must be current and the request must still match the draft's content, otherwise it is refused as stale and
 * nothing is recorded. A rejection needs a reason. The decision is an append-only fact in the engine; approving never issues the quotation. An exact retry replays.
 */
final class DecideQuotationApproval
{
    public function __construct(
        private readonly QuotationOperation $operations,
        private readonly DecideApproval $decide,
        private readonly QuotationApprovals $approvals,
        private readonly QuotationApprovalSubject $subject,
    ) {}

    public function __invoke(User $actor, int $revisionId, int $expectedGeneration, ApprovalDecisionKind $decision, ?string $reason, ?string $requestKey): QuotationApprovalOutcome
    {
        ActiveActor::require($actor, 'use quotations');
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason) > 1000) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A reason of up to 1000 characters is allowed.', 'reason');
        }
        if ($decision === ApprovalDecisionKind::Rejected && ($reason === null || $reason === '')) {
            throw QuotationRefusal::make(QuotationRefusal::REASON_REQUIRED, 'A rejection needs a reason.', 'reason');
        }
        $context = SalesActorContext::forUser($actor, 'quotation.decide_approval');

        $outcome = $this->operations->run(
            $context, 'quotation.decide_approval', $requestKey,
            ['revision_id' => $revisionId, 'expected_generation' => $expectedGeneration, 'decision' => $decision->value, 'reason' => $reason],
            fn (): QuotationRevision => app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_APPROVE, PermissionVocabulary::QUOTATION_VIEW], true),
            fn (QuotationRevision $revision): array => $this->decideOn($actor, $revision, $expectedGeneration, $decision, $reason),
        );

        return QuotationApprovalOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    /** @return array<string, mixed> */
    private function decideOn(User $actor, QuotationRevision $revision, int $expectedGeneration, ApprovalDecisionKind $decision, ?string $reason): array
    {
        if (! $revision->isDraft()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation has an approval to decide.', 'revision_id');
        }
        if ($revision->input_generation !== $expectedGeneration) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_DRAFT_STALE, "The draft is at generation {$revision->input_generation}, not {$expectedGeneration}; review it again before deciding.", 'expected_generation');
        }
        $request = $this->approvals->latest($revision->id);
        if ($request === null || ! $request->isOpen()) {
            throw QuotationRefusal::make(QuotationRefusal::APPROVAL_NOT_PENDING, 'There is no approval waiting for a decision on this draft.', 'revision_id');
        }
        if (! $this->approvals->isCurrent($revision, $request)) {
            throw QuotationRefusal::make(QuotationRefusal::APPROVAL_STALE, 'The draft changed after approval was requested. It must be sent for approval again.', 'revision_id');
        }
        if ($request->submitted_by === $actor->id || $this->subject->tookPart($actor->id, $revision->id)) {
            throw QuotationRefusal::make(QuotationRefusal::APPROVAL_SELF_APPROVAL, 'The person who prepared or requested this cannot decide it.', 'decided_by');
        }
        try {
            $decided = ($this->decide)($request, $actor, $decision, $reason);
        } catch (DomainInvariantException $refusal) {
            throw EngineRefusals::translate($refusal);
        }

        return QuotationApprovalOutcome::result($revision->family_id, $revision->id, $decided->id, $decided->status->value, $revision->input_generation);
    }
}
