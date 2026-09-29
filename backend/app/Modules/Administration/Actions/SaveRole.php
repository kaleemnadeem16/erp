<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

final class SaveRole
{
    public function __construct(private readonly AccessAssignmentGuard $guard, private readonly CompanyContext $company) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(?Role $role, array $attributes, User $actor): Role
    {
        if (! $actor->can('administration.role.manage')) {
            throw new DomainInvariantException('The actor cannot manage roles.', 'name');
        }
        if ($role?->users()->whereKey($actor->id)->exists()) {
            throw new DomainInvariantException('You cannot change a role assigned to your own account.', 'role');
        }
        $this->guard->validate($actor, [], $attributes['permissions']);

        return DB::transaction(function () use ($role, $attributes, $actor): Role {
            $role ??= new Role(['team_id' => $this->company->id(), 'guard_name' => 'web']);
            $before = $role->exists ? ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')] : null;
            $role->fill(['name' => $attributes['name'], 'description' => $attributes['description'] ?? null])->save();
            $role->syncPermissions($attributes['permissions']);
            activity()->performedOn($role)->causedBy($actor)->withProperties(['reason' => $attributes['reason'], 'before' => $before, 'after' => ['name' => $role->name, 'permissions' => $role->permissions()->pluck('name')]])->log($before === null ? 'role created' : 'role changed');

            return $role->refresh()->load('permissions');
        });
    }
}
