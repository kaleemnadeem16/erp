<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Approvals\Support\ApprovalStepStatus;

/**
 * Ends an approval that can no longer be relied on (ADR 0070 §8) — the document changed, or it expired — leaving its
 * decisions untouched as history. A pending request is cancelled; an approved one is marked no longer valid.
 */
final class InvalidateApprovals
{
    public function __invoke(ApprovalRequest $request, string $reason, ApprovalRequestStatus $as = ApprovalRequestStatus::Invalidated): ApprovalRequest
    {
        $request->steps()->whereIn('status', [ApprovalStepStatus::Open, ApprovalStepStatus::Waiting])->update(['status' => ApprovalStepStatus::Closed]);
        $request->update(['status' => $as, 'closed_at' => $request->closed_at ?? now(), 'closing_reason' => $reason]);

        return $request->refresh();
    }

    /** Every open or approved request of a subject, e.g. when its owning module edits it. */
    public function forSubject(string $subjectType, int $subjectId, string $reason): int
    {
        $requests = ApprovalRequest::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->whereIn('status', [ApprovalRequestStatus::Pending, ApprovalRequestStatus::Approved])->get();
        foreach ($requests as $request) {
            $this($request, $reason);
        }

        return $requests->count();
    }
}
