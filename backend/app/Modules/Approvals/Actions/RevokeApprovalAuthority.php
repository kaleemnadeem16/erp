<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Support\ActionAuthorization;

/** Ends an authority for future decisions only; decisions already made keep the snapshot that made them eligible. */
final class RevokeApprovalAuthority
{
    public function __invoke(ApprovalAuthority $authority, User $actor): ApprovalAuthority
    {
        ActionAuthorization::require($actor, 'approvals.authority.manage', 'revoke approval authority');
        if ($authority->revoked_at === null) {
            $authority->update(['revoked_at' => now()]);
        }

        return $authority->refresh();
    }
}
