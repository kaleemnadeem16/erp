<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Modules\Estimating\Support\OverheadPolicyStatus;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Makes a DRAFT policy the live rule for its scope: supersedes whatever was
 * previously ACTIVE for the same (scope_type, scope_id) — closing its
 * effective period the moment this one opens, so overhead_policies_no_overlap
 * never fires here — same shape as ActivateFormulationVersion. The
 * superseding step runs first in the same transaction specifically so
 * overhead_policies_one_active never sees two ACTIVE rows for one scope at
 * once.
 */
final class ActivateOverheadPolicy
{
    public function __invoke(OverheadPolicy $policy, User $actor): OverheadPolicy
    {
        ActionAuthorization::require($actor, 'estimating.overhead-policy.manage', 'activate an overhead policy');
        if ($policy->status !== OverheadPolicyStatus::Draft) {
            throw new DomainInvariantException(
                'Only a DRAFT overhead policy can be activated.',
                field: 'status',
            );
        }

        return DB::transaction(function () use ($policy): OverheadPolicy {
            $today = Date::today()->toDateString();

            OverheadPolicy::query()
                ->forScope($policy->scope_type, $policy->scope_id)
                ->where('status', OverheadPolicyStatus::Active)
                ->update(['status' => OverheadPolicyStatus::Superseded, 'effective_to' => $today]);

            $policy->update([
                'status' => OverheadPolicyStatus::Active,
                'effective_from' => $today,
                'effective_to' => null,
            ]);

            return $policy->refresh();
        });
    }
}
