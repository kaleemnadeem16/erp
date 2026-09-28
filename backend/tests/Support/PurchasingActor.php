<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;

final class PurchasingActor
{
    /** @param list<string> $permissions */
    public static function make(array $permissions, int $companyId = 1): User
    {
        $actor = User::factory()->create();

        return self::grant($actor, $permissions, $companyId);
    }

    /** @param list<string> $permissions */
    public static function grant(User $actor, array $permissions, int $companyId = 1): User
    {
        return PermissionedActor::grant($actor, $permissions, $companyId);
    }
}
