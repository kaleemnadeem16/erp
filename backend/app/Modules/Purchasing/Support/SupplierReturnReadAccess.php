<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Models\User;

/** Who may list or open a supplier return: the people who draft or post one (ADR 0097). Record scope narrows what they see to their warehouses. */
final class SupplierReturnReadAccess
{
    public const PERMISSIONS = ['purchasing.supplier-return.create', 'purchasing.supplier-return.post'];

    public static function allows(?User $user): bool
    {
        return $user?->hasAnyPermission(self::PERMISSIONS) === true;
    }
}
