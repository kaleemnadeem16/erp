<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * Makes a draft the live version of its named policy, superseding whichever was active — in one transaction, so the
 * one-active index never sees two. Requests already routed keep the version they were routed under (ADR 0070 §3).
 */
final class ActivateApprovalPolicy
{
    public function __invoke(ApprovalPolicy $policy, User $actor): ApprovalPolicy
    {
        ActionAuthorization::require($actor, 'approvals.policy.manage', 'activate an approval policy');
        if ($policy->status !== ApprovalPolicyStatus::Draft) {
            throw new DomainInvariantException('Only a draft can be activated.', 'status');
        }

        return DB::transaction(function () use ($policy): ApprovalPolicy {
            $today = now()->toDateString();
            ApprovalPolicy::query()->where('subject_type', $policy->subject_type)->where('code', $policy->code)
                ->where('status', ApprovalPolicyStatus::Active)->update(['status' => ApprovalPolicyStatus::Superseded, 'effective_to' => $today]);
            $policy->update(['status' => ApprovalPolicyStatus::Active, 'effective_from' => $today, 'effective_to' => null]);

            return $policy->refresh()->load(['conditions', 'steps']);
        });
    }
}
