<?php

declare(strict_types=1);

namespace App\Modules\Administration\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;

final class DeleteRole
{
    public function __invoke(Role $role, string $reason, User $actor): void
    {
        if (! $actor->can('administration.role.manage')) {
            throw new DomainInvariantException('The actor cannot manage roles.', 'role');
        }
        if ($role->users()->exists()) {
            throw new DomainInvariantException('An assigned role cannot be deleted.', 'role');
        }
        activity()->performedOn($role)->causedBy($actor)->withProperties(['reason' => $reason, 'permissions' => $role->permissions()->pluck('name')])->log('role deleted');
        $role->delete();
    }
}
