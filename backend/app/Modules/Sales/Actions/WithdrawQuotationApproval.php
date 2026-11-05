<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\WithdrawApproval;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Approval\EngineRefusals;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalOutcome;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovals;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;

/**
 * Takes a pending approval back so the draft can be changed (ADR 0158). `sales.quotation.prepare` with the independent view, judged by the record policy for THIS revision; only
 * the person who requested it (or an approvals manager, the engine's own rule) may withdraw. A reason is required and stays with the engine's append-only request. An approval
 * that is no longer pending cannot be withdrawn. An exact retry replays.
 */
final class WithdrawQuotationApproval
{
    public function __construct(private readonly QuotationOperation $operations, private readonly WithdrawApproval $withdraw, private readonly QuotationApprovals $approvals) {}

    public function __invoke(User $actor, int $revisionId, int $expectedGeneration, string $reason, ?string $requestKey): QuotationApprovalOutcome
    {
        ActiveActor::require($actor, 'use quotations');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw QuotationRefusal::make(QuotationRefusal::REASON_REQUIRED, 'A reason of up to 1000 characters is required to withdraw an approval request.', 'reason');
        }
        $context = SalesActorContext::forUser($actor, 'quotation.withdraw_approval');

        $outcome = $this->operations->run(
            $context, 'quotation.withdraw_approval', $requestKey, ['revision_id' => $revisionId, 'expected_generation' => $expectedGeneration, 'reason' => $reason],
            fn (): QuotationRevision => app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW], true),
            function (QuotationRevision $revision) use ($actor, $expectedGeneration, $reason): array {
                if ($revision->input_generation !== $expectedGeneration) {
                    throw QuotationRefusal::make(QuotationRefusal::QUOTATION_DRAFT_STALE, "The draft is at generation {$revision->input_generation}, not {$expectedGeneration}; review it again first.", 'expected_generation');
                }
                $request = $this->approvals->latest($revision->id);
                if ($request === null || ! $request->isOpen()) {
                    throw QuotationRefusal::make(QuotationRefusal::APPROVAL_NOT_PENDING, 'There is no pending approval request on this draft.', 'revision_id');
                }
                try {
                    $withdrawn = ($this->withdraw)($request, $actor, $reason);
                } catch (DomainInvariantException $refusal) {
                    throw EngineRefusals::translate($refusal);
                }

                return QuotationApprovalOutcome::result($revision->family_id, $revision->id, $withdrawn->id, $withdrawn->status->value, $revision->input_generation);
            },
        );

        return QuotationApprovalOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }
}
