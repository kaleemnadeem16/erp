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
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }
        $actor->givePermissionTo($permissions);

        return $actor;
    }
}
