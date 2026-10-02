<?php

declare(strict_types=1);

namespace App\Modules\Company\Support;

/** Company-owned capabilities registered through the shared permission registry. */
final class PermissionVocabulary
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            CompanyFields::PROFILE,
            CompanyFields::SETTINGS,
            'company.history.view',
        ];
    }
}
