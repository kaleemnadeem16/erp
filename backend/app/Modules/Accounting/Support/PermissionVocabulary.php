<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

final class PermissionVocabulary
{
    /** @return list<string> */
    public static function all(): array
    {
        return ['accounting.chart-of-account.manage'];
    }
}
