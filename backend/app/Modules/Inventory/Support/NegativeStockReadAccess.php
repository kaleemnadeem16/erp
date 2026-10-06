<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Models\User;

/**
 * Who may list or open a negative-stock override request. A request records a shortage — requested, available and short quantities,
 * the reason and the decision — so it is read by the people who raise, approve, override or review one, and by anyone who may
 * already see stock positions (`inventory.stock.view`, ADR 0083) for read-only audit. It carries no cost or settlement figures, so
 * `inventory.valuation.view` is not part of the rule. Same shape as ADR 0074 for recalculation requests.
 */
final class NegativeStockReadAccess
{
    public static function allows(?User $user): bool
    {
        return $user?->hasAnyPermission([
            'inventory.negative-stock.request',
            'inventory.negative-stock.override',
            'inventory.negative-stock.approve',
            'inventory.negative-stock.review',
            StockVisibility::STOCK,
        ]) === true;
    }
}
