<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Models\User;
use App\Support\ActionAuthorization;

/** ADR 0084: field authority is separate from maintaining the surrounding record. */
final class AccountMappingAccess
{
    private const FIELDS = ['income_account_id', 'expense_account_id', 'inventory_account_id', 'receivable_account_id', 'payable_account_id'];

    public static function canView(?User $actor): bool
    {
        return $actor?->canAny(['accounting.account-mapping.view', 'accounting.account-mapping.manage']) === true;
    }

    /** @param array<string, mixed> $attributes */
    public static function canWrite(?User $actor, array $attributes): bool
    {
        return array_intersect_key($attributes, array_flip(self::FIELDS)) === []
            || $actor?->can('accounting.account-mapping.manage') === true;
    }

    /** @param array<string, mixed> $attributes */
    public static function requireWrite(User $actor, array $attributes): void
    {
        if (array_intersect_key($attributes, array_flip(self::FIELDS)) !== []) {
            ActionAuthorization::require($actor, 'accounting.account-mapping.manage', 'change ledger account mappings');
        }
    }
}
