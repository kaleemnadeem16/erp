<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Modules\Approvals\Models\ApprovalDelegation;

/** Ends a delegation for future decisions; decisions already made under it stand as recorded. */
final class RevokeApprovalDelegation
{
    public function __invoke(ApprovalDelegation $delegation): ApprovalDelegation
    {
        if ($delegation->revoked_at === null) {
            $delegation->update(['revoked_at' => now()]);
        }

        return $delegation->refresh();
    }
}
