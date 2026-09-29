<?php

declare(strict_types=1);

namespace App\Modules\Administration\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;

final class AccessAssignmentGuard
{
    public function __construct(private readonly PermissionRegistry $registry, private readonly CompanyContext $company) {}

    /**
     * @param  list<int>  $roleIds
     * @param  list<string>  $directPermissions
     * @return array{roles:list<Role>, permissions:list<string>}
     */
    public function validate(User $actor, array $roleIds, array $directPermissions): array
    {
        $roles = Role::query()->where('team_id', $this->company->id())->whereIn('id', $roleIds)->with('permissions')->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new DomainInvariantException('One or more roles are not available in this company.', 'role_ids');
        }
        $known = $this->registry->all();
        $requested = collect($directPermissions)->merge($roles->flatMap->permissions->pluck('name'))->unique()->values();
        if ($requested->diff($known)->isNotEmpty()) {
            throw new DomainInvariantException('One or more permissions are not registered by the application.', 'direct_permissions');
        }
        $actorPermissions = $actor->getAllPermissions()->pluck('name');
        if ($requested->diff($actorPermissions)->isNotEmpty()) {
            throw new DomainInvariantException('You cannot grant access that you do not hold.', 'direct_permissions');
        }

        return ['roles' => $roles->all(), 'permissions' => array_values(array_unique($directPermissions))];
    }
}
