<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\User;

/** Who may list or open a scrap document: the people who draft or post one (ADR 0097). Record scope narrows what they see to their warehouses. */
final class StockScrapReadAccess
{
    public const PERMISSIONS = ['inventory.scrap.create', 'inventory.scrap.post'];

    public static function allows(?User $user): bool
    {
        return $user?->hasAnyPermission(self::PERMISSIONS) === true;
    }
}
