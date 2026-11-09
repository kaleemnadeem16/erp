<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Support\CompanyContext;
use App\Support\Scope\CompanyAccessFence;
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

        return DB::transaction(function () use ($role, $attributes, $actor): Role {
            // The early checks above only fail fast. Authority is decided here, under the company barrier, from the stored actor.
            app(CompanyAccessFence::class)->lockAll();
            $actor = ActiveActor::require($actor, 'manage roles');
            if (! $actor->can('administration.role.manage')) {
                throw new DomainInvariantException('The actor cannot manage roles.', 'name');
            }
            if ($role !== null && $role->users()->whereKey($actor->id)->exists()) {
                throw new DomainInvariantException('You cannot change a role assigned to your own account.', 'role');
            }
            $this->guard->validate($actor, [], $attributes['permissions']);
            if ($role !== null) {
                $role = Role::query()->whereKey($role->id)->lockForUpdate()->firstOrFail();
                $limitedScopes = AccessScopeLimit::query()->where('role_id', $role->id)->whereIn('scope', [AccessScopeLimit::OWN, AccessScopeLimit::OWN_OR_DELEGATED])->distinct()->pluck('scope');
                foreach ($limitedScopes as $scope) {
                    $duties = $attributes['permissions'];
                    if ($duties === []) {
                        throw new DomainInvariantException('An OWN role needs supported duties.', 'permissions');
                    }
                    foreach ($duties as $duty) {
                        if (! is_string($duty) || ! app(ResourceScopeAdapters::class)->supportsAssignment($duty, $scope)) {
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
