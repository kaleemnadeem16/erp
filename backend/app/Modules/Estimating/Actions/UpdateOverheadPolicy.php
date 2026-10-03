<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Modules\Estimating\Support\OverheadPolicyStatus;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * Only a DRAFT policy may be edited — once ACTIVE or SUPERSEDED it is
 * frozen, see docs/domain/assemblies.md "Overhead allocation". scope_type/
 * scope_id are deliberately not accepted here (see
 * UpdateOverheadPolicyRequest) — a policy doesn't change what it's scoped
 * to; create a new one for a different scope.
 */
final class UpdateOverheadPolicy
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(OverheadPolicy $policy, array $attributes, User $actor): OverheadPolicy
    {
        ActionAuthorization::require($actor, 'estimating.overhead-policy.manage', 'edit an overhead policy');
        if ($policy->status !== OverheadPolicyStatus::Draft) {
            throw new DomainInvariantException(
                'Only a DRAFT overhead policy may be edited; create a new one instead.',
                field: 'status',
            );
        }

        return DB::transaction(function () use ($policy, $attributes): OverheadPolicy {
            $policy->update($attributes);

            return $policy->refresh();
        });
    }
}
