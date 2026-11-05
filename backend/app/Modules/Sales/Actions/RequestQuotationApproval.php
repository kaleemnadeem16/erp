<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalModes;
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
 * Asks for approval of the discount or final-net override a draft carries (ADR 0158). The maker half: `sales.quotation.prepare` with the independent view (both STAGED), judged
 * by the quotation-family record policy for THIS revision (404 when it is not visible). The request is bound to the draft exactly as it is now - `expected_generation` must be
 * current and the content fingerprint travels in the approval's hashed facts - so any later edit makes it stale. Refused when the draft needs no approval, when the company
 * has switched the approval mode off or has no policy that applies (an unconfigured approval is never read as "approved"), and when this very content is already approved.
 * Resubmitting identical content while a request is pending returns that request. One transaction under the revision lock; an exact retry with the same key replays the first result.
 */
final class RequestQuotationApproval
{
    public function __construct(
        private readonly QuotationOperation $operations,
        private readonly SubmitForApproval $submit,
        private readonly ApprovalModes $modes,
        private readonly QuotationApprovals $approvals,
        private readonly AssessQuotationIssueReadiness $readiness,
    ) {}

    public function __invoke(User $actor, int $revisionId, int $expectedGeneration, ?string $requestKey): QuotationApprovalOutcome
    {
        // Capability and record access are judged inside the operation by QuotationAccess, under the company access fence; this is only the early refusal of a non-member.
        ActiveActor::require($actor, 'use quotations');
        $context = SalesActorContext::forUser($actor, 'quotation.request_approval');

        $outcome = $this->operations->run(
            $context, 'quotation.request_approval', $requestKey, ['revision_id' => $revisionId, 'expected_generation' => $expectedGeneration],
            fn (): QuotationRevision => app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW], true),
            fn (QuotationRevision $revision): array => $this->request($actor, $revision, $expectedGeneration),
        );

        return QuotationApprovalOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    /** @return array<string, mixed> */
    private function request(User $actor, QuotationRevision $revision, int $expectedGeneration): array
    {
        if (! $revision->isDraft()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can be sent for approval.', 'revision_id');
        }
        if ($revision->input_generation !== $expectedGeneration) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_DRAFT_STALE, "The draft is at generation {$revision->input_generation}, not {$expectedGeneration}; review it again before asking for approval.", 'expected_generation');
        }
        $standing = $this->approvals->standing($revision)['state'];
        if ($standing === QuotationApprovals::APPROVED) {
            throw QuotationRefusal::make(QuotationRefusal::APPROVAL_ALREADY_GRANTED, 'This draft is already approved as it stands.', 'revision_id');
        }
        if ($standing === QuotationApprovals::REJECTED) {
            throw QuotationRefusal::make(QuotationRefusal::APPROVAL_REJECTED, 'The approver rejected this draft as it stands. Change it before asking again.', 'revision_id');
        }
        if (! $this->needsApproval($actor, $revision)) {
            throw QuotationRefusal::make(QuotationRefusal::APPROVAL_NOT_NEEDED, 'This draft has no discount or price override that needs approval.', 'revision_id');
        }
        // The engine reads "no mode row" as NONE and returns null: for a quotation whose need is already recorded that would be a silent pass, so it is a refusal here.
        if ($this->modes->for(QuotationApprovalSubject::CODE) === ApprovalMode::None) {
            throw QuotationRefusal::make(QuotationRefusal::APPROVAL_NOT_CONFIGURED, 'Approval of quotation discounts is not set up for this company. Ask an administrator to configure it.', 'approval_policy');
        }
        try {
            $request = ($this->submit)(QuotationApprovalSubject::CODE, $revision->id, $actor);
        } catch (DomainInvariantException $refusal) {
            throw EngineRefusals::translate($refusal);
        }
        if ($request === null) {
            throw QuotationRefusal::make(QuotationRefusal::APPROVAL_NOT_CONFIGURED, 'No approval policy applies to this quotation. Ask an administrator to configure one.', 'approval_policy');
        }

        return QuotationApprovalOutcome::result($revision->family_id, $revision->id, $request->id, $request->status->value, $revision->input_generation);
    }

    /**
     * The live need, from the very readiness the issuer relies on (a recorded flag can predate a changed limit): the draft carries a concession that has to be approved when its
     * assessment names an approval blocker. A draft whose header is too incomplete to be priced names none, so it cannot ask yet.
     */
    private function needsApproval(User $actor, QuotationRevision $revision): bool
    {
        foreach (($this->readiness)($actor, $revision->id)['blockers'] as $blocker) {
            if (in_array($blocker['code'], ['APPROVAL_REQUIRED', 'APPROVAL_PENDING', 'APPROVAL_REJECTED'], true)) {
                return true;
            }
        }

        return false;
    }
}
