<?php

declare(strict_types=1);

namespace App\Modules\Administration\Support;

final class PermissionVocabulary
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'administration.member.view',
            'administration.member.manage',
            'administration.role.view',
            'administration.role.manage',
            'administration.access.assign',
            'administration.organisation.view',
            'administration.organisation.manage',
            'administration.custom-field.manage',
        ];
    }
}
