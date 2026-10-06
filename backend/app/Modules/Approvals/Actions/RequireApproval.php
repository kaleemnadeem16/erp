<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalModes;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Approvals\Support\Review\ApprovalReviewRegistry;

/**
 * The guard a consuming module calls before it acts on a document (issue a purchase order, adopt a plan): it passes
 * when no approval is required, or when the latest request was approved *for the document as it is now*. It never
 * approves anything itself. A changed document invalidates its approval (ADR 0070 §8), so a Rs 80,000 approval can
 * never be used to issue a Rs 800,000 order.
 */
final class RequireApproval
{
    public function __construct(
        private readonly ApprovalSubjectRegistry $subjects,
        private readonly ApprovalModes $modes,
        private readonly ResolveApprovalPolicy $resolve,
        private readonly InvalidateApprovals $invalidate,
    ) {}

    public function __invoke(string $subjectType, int $subjectId): void
    {
        $mode = $this->modes->for($subjectType);
        if ($mode === ApprovalMode::None) {
            return;
        }

        $routing = $this->subjects->get($subjectType)->routing($subjectId);
        $latest = ApprovalRequest::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)->orderByDesc('id')->first();
        if ($latest !== null && $latest->status === ApprovalRequestStatus::Approved && ($latest->facts_hash !== $routing->hash() || ! app(ApprovalReviewRegistry::class)->unchanged($latest))) {
            ($this->invalidate)($latest, 'The document changed after it was approved; it must be submitted again.');
            $latest = null;
        }

        if (($this->resolve)($subjectType, $routing) === null) {
            if ($mode === ApprovalMode::Required) {
                throw new DomainInvariantException('This requires approval but no approval policy applies to it. Ask an administrator to configure one.', 'approval_policy');
            }

            return;
        }
        if ($latest === null || $latest->status === ApprovalRequestStatus::Invalidated) {
            throw new DomainInvariantException('This needs approval first. Submit it for approval.', 'approval');
        }
        if ($latest->status === ApprovalRequestStatus::Pending) {
            throw new DomainInvariantException('This is still waiting for approval.', 'approval');
        }
        if ($latest->status !== ApprovalRequestStatus::Approved) {
            throw new DomainInvariantException("Its last approval was {$latest->status->value}; submit it for approval again.", 'approval');
        }
    }
}
