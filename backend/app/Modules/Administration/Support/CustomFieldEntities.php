<?php

declare(strict_types=1);

namespace App\Modules\Administration\Support;

use App\Models\User;

/** Only entities with implemented value storage and validation may be configured. */
final class CustomFieldEntities
{
    public const TYPES = ['items', 'parties', 'supplier_accounts', 'customer_accounts'];

    public static function canRead(?User $actor, string $entity): bool
    {
        if ($actor?->can('administration.custom-field.manage')) {
            return true;
        }

        return match ($entity) {
            'items' => $actor?->can('inventory.item.manage') === true,
            'parties' => $actor?->can('parties.party.manage') === true,
            'supplier_accounts' => $actor?->can('parties.supplier-account.manage') === true,
            'customer_accounts' => $actor?->can('parties.customer-account.manage') === true,
            default => false,
        };
    }
}
