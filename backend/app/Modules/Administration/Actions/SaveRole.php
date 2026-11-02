<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use App\Support\CompanyContext;
use App\Support\Scope\ResourceScopeAdapters;
use Illuminate\Support\Facades\DB;

final class SaveRole
{
    public function __construct(private readonly AccessAssignmentGuard $guard, private readonly CompanyContext $company) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(?Role $role, array $attributes, User $actor): Role
    {
        $this->guard->assertCompany($actor);
        if ($role !== null) {
            $this->guard->assertCompany($role);
        }
        if (! $actor->can('administration.role.manage')) {
            throw new DomainInvariantException('The actor cannot manage roles.', 'name');
        }
        if ($role?->users()->whereKey($actor->id)->exists()) {
            throw new DomainInvariantException('You cannot change a role assigned to your own account.', 'role');
        }
        $this->guard->validate($actor, [], $attributes['permissions']);

        return DB::transaction(function () use ($role, $attributes, $actor): Role {
            if ($role !== null) {
                $role = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
                $hasOwnLimits = AccessScopeLimit::query()->where('role_id', $role->id)->where('scope', AccessScopeLimit::OWN)->exists();
                if ($hasOwnLimits) {
                    $duties = $attributes['permissions'];
                    if ($duties === []) {
                        throw new DomainInvariantException('An OWN role needs supported duties.', 'permissions');
                    }
                    foreach ($duties as $duty) {
                        if (! is_string($duty) || ! app(ResourceScopeAdapters::class)->supportsAssignment($duty, AccessScopeLimit::OWN)) {
                            throw new DomainInvariantException('A role limited to OWN cannot acquire unsupported duties.', 'permissions');
                        }
                    }
                }
            }
            $role ??= new Role(['team_id' => $this->company->id(), 'guard_name' => 'web']);
            $before = $role->exists ? ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')] : null;
            $role->fill(['name' => $attributes['name'], 'description' => $attributes['description'] ?? null])->save();
            $role->syncPermissions($attributes['permissions']);
            activity()->performedOn($role)->causedBy($actor)->withProperties(['reason' => $attributes['reason'], 'before' => $before, 'after' => ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')]])->log($before === null ? 'role created' : 'role changed');

            return $role->refresh()->load('permissions');
        });
    }
}
