<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Modules\Estimating\Support\OverheadPolicyStatus;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class DeleteOverheadPolicy
{
    public function __invoke(OverheadPolicy $policy, User $actor): void
    {
        ActionAuthorization::require($actor, 'estimating.overhead-policy.manage', 'delete an overhead policy');
        if ($policy->status !== OverheadPolicyStatus::Draft) {
            throw new DomainInvariantException(
                'Only a DRAFT overhead policy may be deleted; ACTIVE and SUPERSEDED versions are kept.',
                field: 'status',
            );
        }

        DB::transaction(fn () => $policy->delete());
    }
}
