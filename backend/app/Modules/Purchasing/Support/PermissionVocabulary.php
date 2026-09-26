<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

/** Purchasing-owned capabilities registered through the shared permission registry. */
final class PermissionVocabulary
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'purchasing.over-receipt-policy.manage',
            'purchasing.policy-history.view',
            'purchasing.cutover.commit',
        ];
    }
}
