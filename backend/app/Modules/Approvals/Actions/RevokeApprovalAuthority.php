<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalAuthority;

/** Ends an authority for future decisions only; decisions already made keep the snapshot that made them eligible. */
final class RevokeApprovalAuthority
{
    public function __invoke(ApprovalAuthority $authority): ApprovalAuthority
    {
        if ($authority->revoked_at === null) {
            $authority->update(['revoked_at' => now()]);
        }

        return $authority->refresh();
    }
}
