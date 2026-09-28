<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use Brick\Math\BigDecimal;

/**
 * One item's aggregate stock at one location (and batch, when the item is
 * batch-tracked), as read from stock_layers — the authoritative per-location
 * quantity for every valuation method (IssuePoster consumes layers even for
 * AVCO items).
 *
 * The three quantities partition `onHand`: `held` is stock awaiting QA
 * release, `unusableLocation` is stock (not already held) sitting somewhere
 * `LocationType::holdsUsableStock()` refuses, and `usable` is the rest. That
 * partition is what lets a user reconcile a shortfall against the inventory
 * overview's location-agnostic "available" figure.
 *
 * `unitCost` is null — never zero — when there is no defensible cost evidence.
 */
final class StockBalance
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $warehouseId,
        public readonly string $warehouseCode,
        public readonly string $warehouseName,
        public readonly int $locationId,
        public readonly string $locationCode,
        public readonly string $locationName,
        public readonly LocationType $locationType,
        public readonly bool $locationUsable,
        public readonly ?int $batchId,
        public readonly ?string $batchCode,
        public readonly BigDecimal $onHand,
        public readonly BigDecimal $held,
        public readonly BigDecimal $unusableLocation,
        public readonly BigDecimal $usable,
        public readonly ?BigDecimal $unitCost,
        public readonly ?string $costMethod,
    ) {}

    /** Stable identity used as the final, deterministic tie-breaker. */
    public function key(): string
    {
        return sprintf('%010d:%010d', $this->locationId, $this->batchId ?? 0);
    }
}
