<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Support\ActionAuthorization;

/** Ends a delegation for future decisions; decisions already made under it stand as recorded. */
final class RevokeApprovalDelegation
{
    public function __invoke(ApprovalDelegation $delegation, User $actor): ApprovalDelegation
    {
        $delegation = ApprovalDelegation::query()->findOrFail($delegation->id);
        // The delegator may withdraw their own delegation; revoking someone else's takes the delegation-management capability.
        if ($delegation->delegator_id !== $actor->id) {
            ActionAuthorization::require($actor, 'approvals.delegation.manage', 'revoke another person\'s delegation');
        }
        if ($delegation->revoked_at === null) {
            $delegation->update(['revoked_at' => now()]);
        }

        return $delegation->refresh();
    }
}
