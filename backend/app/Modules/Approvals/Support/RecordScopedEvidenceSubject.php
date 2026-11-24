<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;

/**
 * A subject whose figures are visible per record, not per person: `canViewEvidence` says the person holds a commercial capability at all, and this says
 * whether that capability reaches THIS request's document. Both must hold. A person asked to decide, or who already decided, keeps seeing it as before.
 */
interface RecordScopedEvidenceSubject extends ApprovalSubject
{
    public function evidenceInReach(User $user, ApprovalRequest $request): bool;
}
