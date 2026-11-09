<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Quotation\QuotationFamilyScopeAdapter;
use App\Support\Demo\QuotationDemoBoundary;
use App\Support\Scope\CompanyAccessFence;
use App\Support\Scope\RecordScope;
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

        return DB::transaction(function () use ($member, $attributes, $actor): User {
            // The early checks above only fail fast. Authority is decided here, under the company barrier, from the stored actor.
            app(CompanyAccessFence::class)->lockAll();
            $actor = ActiveActor::require($actor, 'assign access');
            $member = User::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $this->guard->assertCompany($member);
            if (! $actor->can('administration.access.assign')) {
                throw new DomainInvariantException('The actor cannot assign access.', 'role_ids');
            }
            if ($actor->is($member)) {
                throw new DomainInvariantException('You cannot change your own access.', 'role_ids');
            }
            $access = $this->guard->validate($actor, $attributes['role_ids'], $attributes['direct_permissions']);
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
     * Exact demo-only subset operation (ADR 0159). Never authorises an ordinary full replacement.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function patchQuotationDemo(User $member, array $attributes, User $actor): User
    {
        $this->guard->assertCompany($member);
        $this->guard->assertCompany($actor);
        if ($actor->is($member)) {
            throw new DomainInvariantException('You cannot change your own access.', 'role_ids');
        }

        QuotationDemoBoundary::assertCompany((int) $member->company_id);

        return DB::transaction(function () use ($member, $attributes, $actor): User {
            app(CompanyAccessFence::class)->lockAll();
            $actor = ActiveActor::require($actor, 'assign access');
            $member = User::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            QuotationDemoBoundary::assertMainPatch($member, $actor);
            if (! $actor->can('administration.access.assign')) {
                throw new DomainInvariantException('The actor cannot assign access.', 'role_ids');
            }
            if (array_diff(array_keys($attributes), ['role_ids', 'direct_permissions', 'scope_limits', 'reason']) !== []
                || ! is_array($attributes['role_ids'] ?? null) || ! is_array($attributes['direct_permissions'] ?? null)
                || ! is_array($attributes['scope_limits'] ?? null)
                || ! array_is_list($attributes['role_ids']) || ! array_is_list($attributes['direct_permissions']) || ! array_is_list($attributes['scope_limits'])
                || count(array_filter($attributes['role_ids'], fn ($id): bool => ! is_int($id) || $id < 1)) > 0
                || count(array_filter($attributes['direct_permissions'], fn ($name): bool => ! is_string($name))) > 0
                || count(array_filter($attributes['scope_limits'], fn ($row): bool => ! is_array($row))) > 0
                || ! is_string($attributes['reason'] ?? null)
                || trim($attributes['reason']) === '' || mb_strlen($attributes['reason']) > 1000) {
                throw new DomainInvariantException('A complete quotation-only access patch and reason are required.', 'direct_permissions');
            }
            $duties = QuotationDemoBoundary::DUTIES;
            if (array_diff($attributes['direct_permissions'], $duties) !== []) {
                throw new DomainInvariantException('The demo patch cannot manage another duty.', 'direct_permissions');
            }
            $access = $this->guard->validate($actor, $attributes['role_ids'], $attributes['direct_permissions']);
            foreach ($access['roles'] as $role) {
                $names = $role->permissions->pluck('name')->all();
                if ($names === [] || array_diff($names, $duties) !== []) {
                    throw new DomainInvariantException('The demo patch requires quotation-only roles.', 'role_ids');
                }
            }
            $requested = collect($access['permissions'])->merge(collect($access['roles'])->flatMap(fn (Role $role) => $role->permissions->pluck('name')))->unique();
            foreach ($requested as $duty) {
                if (! app(RecordScope::class)->resolveForResource($actor, $duty, QuotationFamilyScopeAdapter::RESOURCE)->isAll()) {
                    throw new DomainInvariantException('The synthetic setup operator must independently hold company-wide scope for every quotation duty it administers.', 'scope_limits');
                }
            }
            $oldRoleIds = [];
            foreach (Role::query()->where('team_id', $member->company_id)->whereIn('id', $member->roles()->pluck('roles.id'))->with('permissions')->get() as $role) {
                $names = $role->permissions->pluck('name')->all();
                if (array_intersect($names, $duties) === []) {
                    continue;
                }
                if (array_diff($names, $duties) !== []) {
                    throw new DomainInvariantException('A mixed existing role prevents a quotation-only patch.', 'role_ids');
                }
                $oldRoleIds[] = (int) $role->id;
            }
            $newRoleIds = array_map(fn (Role $role): int => (int) $role->id, $access['roles']);
            $managed = ['role_ids' => array_values(array_unique([...$oldRoleIds, ...$newRoleIds])), 'permissions' => $duties];
            $before = $this->quotationPatchState($member, $oldRoleIds, $duties);
            // Validate scope rows before returning an unchanged result, too. Invalid input never becomes a successful no-op.
            $this->syncLimits($member, $attributes, $newRoleIds, $access['permissions'], $actor, $managed);
            $oldDirect = $before['direct_permissions'];
            foreach (array_diff($oldRoleIds, $newRoleIds) as $id) {
                $member->removeRole($id);
            }
            foreach ($access['roles'] as $role) {
                if (! in_array((int) $role->id, $oldRoleIds, true)) {
                    $member->assignRole($role);
                }
            }
            foreach (array_diff($oldDirect, $access['permissions']) as $name) {
                $member->revokePermissionTo($name);
            }
            foreach (array_diff($access['permissions'], $oldDirect) as $name) {
                $member->givePermissionTo($name);
            }
            $after = $this->quotationPatchState($member->refresh(), $newRoleIds, $duties);
            if ($before !== $after) {
                $member->forceFill(['access_updated_at' => now()])->save();
                activity()->performedOn($member)->causedBy($actor)->withProperties(['reason' => $attributes['reason'], 'before' => $before, 'after' => $after, 'decision' => 'ADR 0159'])->log('quotation demo access patched');
            }

            return $member->refresh();
        });
    }

    /** @param list<int> $roleIds
     * @param  list<string>  $duties
     * @return array{roles:list<int>,direct_permissions:list<string>,scope_limits:list<array{role_id:int|null,permission:string|null,scope:string}>}
     */
    private function quotationPatchState(User $member, array $roleIds, array $duties): array
    {
        sort($roleIds);
        $direct = $member->getDirectPermissions()->pluck('name')->intersect($duties)->sort()->values()->all();
        $limits = array_values(array_filter($this->limits($member), fn (array $row): bool => in_array($row['role_id'], $roleIds, true) || in_array($row['permission'], $duties, true)));
        usort($limits, fn (array $a, array $b): int => [$a['role_id'], $a['permission']] <=> [$b['role_id'], $b['permission']]);

        return ['roles' => $roleIds, 'direct_permissions' => $direct, 'scope_limits' => $limits];
    }

    /**
     * "Only my units" on an assignment (ADR 0089). Sent limits replace the person's limits; omitted ones are kept. Either way a limit
     * whose role or direct permission is no longer assigned is dropped, so a restriction never outlives what it restricted.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int|string>  $roleIds
     * @param  list<string>  $permissions
     * @param  array{role_ids:list<int>,permissions:list<string>}|null  $only
     */
    private function syncLimits(User $member, array $attributes, array $roleIds, array $permissions, User $actor, ?array $only = null): void
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
            if (! in_array($scope, [AccessScopeLimit::MY_UNITS, AccessScopeLimit::OWN, AccessScopeLimit::OWN_OR_DELEGATED], true)) {
                throw new DomainInvariantException('This scope mode is not supported.', 'scope_limits');
            }
            if (in_array($scope, [AccessScopeLimit::OWN, AccessScopeLimit::OWN_OR_DELEGATED], true)) {
                $role = $roleId === null ? null : Role::query()->whereKey($roleId)->lockForUpdate()->firstOrFail();
                $duties = $role === null ? [$permission] : $role->permissions()->pluck('name')->all();
                if ($duties === []) {
                    throw new DomainInvariantException('An OWN role needs supported duties.', 'scope_limits');
                }
                foreach ($duties as $duty) {
                    if (! is_string($duty) || ! app(ResourceScopeAdapters::class)->supportsAssignment($duty, $scope)) {
                        throw new DomainInvariantException('OWN cannot restrict an unsupported or mixed-duty assignment.', 'scope_limits');
                    }
                }
            }
            $rows[] = ['role_id' => $roleId, 'permission' => $permission, 'scope' => $scope];
        }
        $existing = AccessScopeLimit::query()->where('user_id', $member->id);
        if ($only !== null) {
            $existing->where(fn ($query) => $query->whereIn('role_id', $only['role_ids'])->orWhereIn('permission', $only['permissions']));
            $stored = $existing->get(['role_id', 'permission', 'scope'])->map(fn ($row): array => ['role_id' => $row->role_id, 'permission' => $row->permission, 'scope' => $row->scope])->all();
            $normalise = function (array $values): array {
                usort($values, fn (array $a, array $b): int => [$a['role_id'], $a['permission']] <=> [$b['role_id'], $b['permission']]);

                return $values;
            };
            if ($normalise($stored) === $normalise($rows)) {
                return;
            }
        }
        $existing->delete();
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
