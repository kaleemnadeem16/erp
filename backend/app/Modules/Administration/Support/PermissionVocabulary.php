<?php

declare(strict_types=1);

namespace App\Modules\Administration\Support;

final class PermissionVocabulary
{
    public static function all(): array
    {
        return [
            'administration.member.view',
            'administration.member.manage',
            'administration.role.view',
            'administration.role.manage',
            'administration.access.assign',
        ];
    }
}
