<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/** Approvals-owned capabilities registered through the shared permission registry. */
final class PermissionVocabulary
{
    public const TECHNICAL_REVIEW = 'approvals.document.technical-review';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            'approvals.policy.manage',
            'approvals.authority.manage',
            'approvals.delegation.manage',
            'approvals.settings.manage',
            'approvals.history.view',
            'approvals.authority.view',
            'approvals.reason.view',
            self::TECHNICAL_REVIEW,
        ];
    }
}
