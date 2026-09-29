<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use Illuminate\Support\Facades\DB;

final class SyncMemberAccess
{
    public function __construct(private readonly AccessAssignmentGuard $guard) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(User $member, array $attributes, User $actor): User
    {
        if (! $actor->can('administration.access.assign')) {
            throw new DomainInvariantException('The actor cannot assign access.', 'role_ids');
        }
        if ($actor->is($member)) {
            throw new DomainInvariantException('You cannot change your own access.', 'role_ids');
        }
        $access = $this->guard->validate($actor, $attributes['role_ids'], $attributes['direct_permissions']);

        return DB::transaction(function () use ($member, $attributes, $actor, $access): User {
            $before = ['roles' => $member->getRoleNames()->values(), 'direct_permissions' => $member->getDirectPermissions()->pluck('name')->values()];
            $member->syncRoles($access['roles']);
            $member->syncPermissions($access['permissions']);
            $after = ['roles' => $member->getRoleNames()->values(), 'direct_permissions' => $member->getDirectPermissions()->pluck('name')->values()];
            activity()->performedOn($member)->causedBy($actor)->withProperties(['reason' => $attributes['reason'], 'before' => $before, 'after' => $after])->log('member access changed');

            return $member->refresh();
        });
    }
}
