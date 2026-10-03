<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\User;

/**
 * Who may read which layer of stock information (ADR 0083). The layers are separate on purpose, and separate from
 * `inventory.valuation.view` (value and cost), which keeps its own check:
 *
 *  - availability: the item-level summary — on hand, available, QA-held, on order, shortage — for people who need to know what can
 *    be promised (sales, estimating, purchasing, planning) and not where it is or how it moved;
 *  - stock: the position — balances by warehouse, location, batch and serial, layers, AVCO pool quantities — for the warehouse and
 *    inventory control. Holding it also gives the availability summary, so a warehouse role does not need to list both;
 *  - movement: the ledger — who moved stock, when, from which source document. It stands alone: it grants no balances.
 *
 * Quantities that belong to a document a user may see (a PO's outstanding quantity, a receipt's received quantity) follow that
 * document's own permission, not these.
 */
final class StockVisibility
{
    public const AVAILABILITY = 'inventory.availability.view';

    public const STOCK = 'inventory.stock.view';

    public const MOVEMENT = 'inventory.movement.view';

    public static function availability(?User $user): bool
    {
        return $user?->hasAnyPermission([self::AVAILABILITY, self::STOCK]) === true;
    }

    public static function stock(?User $user): bool
    {
        return $user?->hasPermissionTo(self::STOCK) === true;
    }

    public static function movement(?User $user): bool
    {
        return $user?->hasPermissionTo(self::MOVEMENT) === true;
    }

    /**
     * The system quantity on a physical-count line. A counter who holds only `inventory.physical_count.count` counts blind: the book
     * figure is shown to whoever sets up or posts a count, and to anyone who may see stock positions anyway.
     */
    public static function bookQuantity(?User $user): bool
    {
        return $user?->hasAnyPermission([self::STOCK, 'inventory.physical_count.create', 'inventory.physical_count.post']) === true;
    }
}
