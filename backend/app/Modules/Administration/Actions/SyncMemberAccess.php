<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use App\Support\Scope\ResourceScopeAdapters;
use Illuminate\Support\Facades\DB;

final class SyncMemberAccess
{
    public function __construct(private readonly AccessAssignmentGuard $guard) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(User $member, array $attributes, User $actor): User
    {
        $this->guard->assertCompany($actor);
        $this->guard->assertCompany($member);
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
            $limitsBefore = $this->limits($member);
            $this->syncLimits($member, $attributes, collect($access['roles'])->map->getKey()->all(), $access['permissions'], $actor);
            $member->forceFill(['access_updated_at' => now()])->save();
            $before['scope_limits'] = $limitsBefore;
            $after = ['roles' => $member->getRoleNames()->values(), 'direct_permissions' => $member->getDirectPermissions()->pluck('name')->values(), 'scope_limits' => $this->limits($member)];
            activity()->performedOn($member)->causedBy($actor)->withProperties(['reason' => $attributes['reason'] ?? null, 'before' => $before, 'after' => $after])->log('member access changed');

            return $member->refresh();
        });
    }

    /**
     * "Only my units" on an assignment (ADR 0089). Sent limits replace the person's limits; omitted ones are kept. Either way a limit
     * whose role or direct permission is no longer assigned is dropped, so a restriction never outlives what it restricted.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $roleIds
     * @param  list<string>  $permissions
     */
    private function syncLimits(User $member, array $attributes, array $roleIds, array $permissions, User $actor): void
    {
        $roleIds = array_map('intval', $roleIds);
        $desired = array_key_exists('scope_limits', $attributes) ? $attributes['scope_limits'] : $this->limits($member);
        $rows = [];
        foreach ($desired as $limit) {
            $roleId = isset($limit['role_id']) ? (int) $limit['role_id'] : null;
            $permission = $limit['permission'] ?? null;
            if (($roleId === null) === ($permission === null)) {
                throw new DomainInvariantException('A limit applies to exactly one role or one direct permission.', 'scope_limits');
            }
            $assigned = $roleId !== null ? in_array($roleId, $roleIds, true) : in_array($permission, $permissions, true);
            if (! $assigned) {
                if (array_key_exists('scope_limits', $attributes)) {
                    throw new DomainInvariantException('A limit can only apply to a role or permission this person is being given.', 'scope_limits');
                }

                continue; // a stale limit from before the role or permission was removed
            }
            $scope = $limit['scope'] ?? AccessScopeLimit::MY_UNITS;
            if (! in_array($scope, [AccessScopeLimit::MY_UNITS, AccessScopeLimit::OWN], true)) {
                throw new DomainInvariantException('This scope mode is not supported.', 'scope_limits');
            }
            if ($scope === AccessScopeLimit::OWN) {
                $role = $roleId === null ? null : Role::query()->whereKey($roleId)->lockForUpdate()->firstOrFail();
                $duties = $role === null ? [$permission] : $role->permissions()->pluck('name')->all();
                if ($duties === []) {
                    throw new DomainInvariantException('An OWN role needs supported duties.', 'scope_limits');
                }
                foreach ($duties as $duty) {
                    if (! is_string($duty) || ! app(ResourceScopeAdapters::class)->supportsAssignment($duty, AccessScopeLimit::OWN)) {
                        throw new DomainInvariantException('OWN cannot restrict an unsupported or mixed-duty assignment.', 'scope_limits');
                    }
                }
            }
            $rows[] = ['role_id' => $roleId, 'permission' => $permission, 'scope' => $scope];
        }
        AccessScopeLimit::query()->where('user_id', $member->id)->delete();
        foreach ($rows as $row) {
            AccessScopeLimit::query()->create($row + ['user_id' => $member->id, 'created_by' => $actor->id]);
        }
    }

    /** @return list<array{role_id: int|null, permission: string|null, scope: string}> */
    private function limits(User $member): array
    {
        return AccessScopeLimit::query()->where('user_id', $member->id)->orderBy('id')->get()
            ->map(fn (AccessScopeLimit $l): array => ['role_id' => $l->role_id, 'permission' => $l->permission, 'scope' => $l->scope])->all();
    }
}
