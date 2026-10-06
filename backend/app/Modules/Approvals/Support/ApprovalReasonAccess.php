<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Models\ApprovalRequest;

/**
 * Who may read the free text people write on an approval: the reason on a decision, and the closing reason when a person rejected or
 * withdrew the request (ADR 0087, Q35). A reason is unstructured, so it can say what the structured fields are withheld to protect — a
 * price, a margin, a supplier's terms — and scrubbing numbers out of text is not a control. It therefore follows the evidence:
 *
 *  - anyone who may see the request's full evidence (ApprovalEvidenceAccess: the document module's own field policy, the person asked to
 *    decide, someone who already decided) reads every reason on it;
 *  - holders of `approvals.reason.view` (an auditor an administrator names) read every reason;
 *  - the author reads their own: a decider (or the principal they acted for) their decision's reason and a rejection's closing reason,
 *    the submitter their withdrawal. A submitter who is not otherwise entitled to the evidence does not read a rejection's reason.
 *
 * Everyone else who may open the request (history readers, a technical-only reviewer) still sees the outcome, who decided and when.
 * Reasons the system writes ("The approval expired…", "The order was cancelled.") quote no figures and stay visible.
 */
final class ApprovalReasonAccess
{
    /** Statuses whose closing reason is written by a person rather than by the system. */
    private const HUMAN_CLOSING = [ApprovalRequestStatus::Rejected, ApprovalRequestStatus::Withdrawn];

    /** May this user read every person-written reason on the request? */
    public function readsAll(?User $user, bool $evidenceVisible): bool
    {
        return $user !== null && ($evidenceVisible || $user->can('approvals.reason.view'));
    }

    public function decisionReason(?User $user, ApprovalDecision $decision, bool $readsAll): bool
    {
        return $readsAll || ($user !== null && in_array($user->id, [$decision->decided_by, $decision->principal_id], true));
    }

    public function closingReason(?User $user, ApprovalRequest $request, bool $readsAll): bool
    {
        if (! in_array($request->status, self::HUMAN_CLOSING, true) || $readsAll) {
            return true;
        }
        if ($user === null) {
            return false;
        }
        // A withdrawal is the submitter's own words. A rejection's closing reason is the rejecting decider's: the same text as that
        // decision's reason, so it follows the same author rule rather than being more widely readable than the decision it repeats.
        if ($request->status === ApprovalRequestStatus::Withdrawn) {
            return $request->submitted_by === $user->id;
        }
        $request->loadMissing('steps.decisions');

        return $request->steps->flatMap->decisions->contains(
            fn (ApprovalDecision $d): bool => $d->decision === ApprovalDecisionKind::Rejected && in_array($user->id, [$d->decided_by, $d->principal_id], true),
        );
    }
}
