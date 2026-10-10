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
 * the location's own mark, or `LocationType::holdsUsableStock()` when unmarked, refuses, and `usable` is the rest. That
 * partition is what lets a user reconcile a shortfall against the inventory
 * overview's location-agnostic "available" figure.
 *
 * `unitCost`/`costMethod` are not read from stock: they are ranking evidence attached by the consumer from
 * QueryMaterialCost (see `withCostEvidence`). Null means no complete evidence — never zero; a genuine zero is 0.
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
        public readonly ?BigDecimal $unitCost = null,
        public readonly ?string $costMethod = null,
        /** The company's own usable/not-usable mark on the location, or null when it follows the location's type. */
        public readonly ?bool $locationMarked = null,
    ) {}

    /** Stable identity used as the final, deterministic tie-breaker. */
    public function key(): string
    {
        return sprintf('%010d:%010d', $this->locationId, $this->batchId ?? 0);
    }

    /** The same balance carrying cost evidence the consumer resolved for it (null = no complete evidence). */
    public function withCostEvidence(?BigDecimal $unitCost, ?string $costMethod): self
    {
        return new self($this->itemId, $this->warehouseId, $this->warehouseCode, $this->warehouseName, $this->locationId,
            $this->locationCode, $this->locationName, $this->locationType, $this->locationUsable, $this->batchId, $this->batchCode,
            $this->onHand, $this->held, $this->unusableLocation, $this->usable, $unitCost, $costMethod, $this->locationMarked);
    }
}
