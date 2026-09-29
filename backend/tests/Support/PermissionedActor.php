<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class PermissionedActor
{
    /** @param list<string> $permissions */
    public static function grant(User $actor, array $permissions, int $companyId = 1): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
        $registered = array_map(fn (string $permission) => Permission::findOrCreate($permission, 'web'), $permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->givePermissionTo($registered);

        return $actor;
    }
}
