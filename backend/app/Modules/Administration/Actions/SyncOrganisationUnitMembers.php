<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Administration\Support\MemberStatus;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * Sets who belongs to a unit. A person can belong to several units; belonging is not itself access: it matters only for assignments an
 * administrator limited to "my units" (ADR 0089). Only members of this company can be added.
 */
final class SyncOrganisationUnitMembers
{
    /** @param  list<int>  $userIds */
    public function __invoke(OrganisationUnit $unit, array $userIds, User $actor): OrganisationUnit
    {
        ActionAuthorization::require($actor, 'administration.organisation.manage', 'manage organisation units');
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        return DB::transaction(function () use ($unit, $userIds, $actor): OrganisationUnit {
            $unit = OrganisationUnit::query()->lockForUpdate()->findOrFail($unit->id);
            $valid = User::query()->where('company_id', $unit->company_id)->whereIn('id', $userIds)->where('status', '!=', MemberStatus::Suspended->value)->pluck('id')->all();
            if (count($valid) !== count($userIds)) {
                throw new DomainInvariantException('Only active members of this company can belong to a unit.', 'user_ids');
            }
            $before = $unit->members()->pluck('users.id')->sort()->values()->all();
            $unit->members()->sync($userIds);
            $after = collect($userIds)->sort()->values()->all();
            if ($before !== $after) {
                activity()->performedOn($unit)->causedBy($actor)->withProperties(['before' => ['member_ids' => $before], 'after' => ['member_ids' => $after]])->log('unit members changed');
            }

            return $unit->refresh();
        });
    }
}
