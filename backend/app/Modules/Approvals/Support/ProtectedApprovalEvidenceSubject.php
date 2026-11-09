<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;

/** Independent evidence authority that also applies to current and past deciders. */
interface ProtectedApprovalEvidenceSubject extends ApprovalSubject
{
    public function mayReadRequestEvidence(User $actor, ApprovalRequest $request): bool;
}
