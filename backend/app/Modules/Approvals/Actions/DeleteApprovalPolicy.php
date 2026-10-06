<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Support\ActionAuthorization;

/** A draft that was never activated can be discarded; anything else is history that requests may point at. */
final class DeleteApprovalPolicy
{
    public function __invoke(ApprovalPolicy $policy, User $actor): void
    {
        $policy = ApprovalPolicy::query()->findOrFail($policy->id);
        ActionAuthorization::require($actor, 'approvals.policy.manage', 'delete an approval policy');
        if ($policy->status !== ApprovalPolicyStatus::Draft) {
            throw new DomainInvariantException('Only a draft can be deleted; an active or superseded version is history.', 'status');
        }
        $policy->delete();
    }
}
