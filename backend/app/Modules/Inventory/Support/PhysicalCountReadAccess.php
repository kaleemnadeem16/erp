<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\User;

/**
 * Who may list or open a physical count. A count lists items, locations and the quantities counted there, and (to whoever may see
 * them) the book quantity and found cost, so it is read by the people who set up, count or post one, by anyone who may see stock
 * positions (`inventory.stock.view`, ADR 0083) and by valuation readers, who audit its cost effect. What each of them sees *inside* a
 * line is still projected separately (blind counting, found cost). Same shape as ADR 0074 for recalculation requests.
 */
final class PhysicalCountReadAccess
{
    /** Any one of these opens the read. Public so the access-inventory command reports from the same list the gate uses. */
    public const PERMISSIONS = [
        'inventory.physical_count.create',
        'inventory.physical_count.count',
        'inventory.physical_count.post',
        StockVisibility::STOCK,
        'inventory.valuation.view',
    ];

    public static function allows(?User $user): bool
    {
        return $user?->hasAnyPermission(self::PERMISSIONS) === true;
    }
}
