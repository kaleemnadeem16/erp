<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalRequestStep;

/**
 * Who may see the money in an approval (the routed amount and its currency evidence). Seeing *that an approval exists and how it
 * was decided* is a separate, wider right (ApprovalRequestController::show); the figures follow the document's own field
 * policy, because approval authority is not a licence to read commercial or cost data (docs/architecture/authorization.md).
 *
 * Allowed:
 *  1. whoever the document's module says may see its figures (`ApprovalSubject::canViewEvidence`);
 *  2. the person being asked to decide an open step — they cannot decide blind, and the access ends with the request;
 *  3. a person who has already decided or acted on it — it is the basis of their own recorded decision.
 * Everyone else, including a pure auditor, gets the status, the steps and the decisions without the money.
 */
final class ApprovalEvidenceAccess
{
    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    public function canView(?User $user, ApprovalRequest $request, ?ApprovalRequestStep $actionable): bool
    {
        if ($user === null) {
            return false;
        }
        if ($actionable !== null) {
            return true;
        }
        $request->loadMissing('steps.decisions');
        if ($request->steps->flatMap->decisions->contains(fn ($decision): bool => in_array($user->id, [$decision->decided_by, $decision->principal_id], true))) {
            return true;
        }

        return $this->subjects->has($request->subject_type) && $this->subjects->get($request->subject_type)->canViewEvidence($user);
    }
}
