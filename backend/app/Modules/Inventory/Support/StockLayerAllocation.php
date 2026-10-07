<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * One stock layer's contribution to a posted issue or transfer: the layer that was drawn on and how much of it. For a transfer,
 * `destinationLayerId` is the layer the poster created to carry that quantity at the destination (ADR 0090 §5).
 *
 * It describes the posting only. It carries no cost and says nothing about packaging, so it means the same for FIFO, AVCO and any
 * future tracking mode. Quantities are four-decimal strings, exactly as the ledger stores them.
 */
final class StockLayerAllocation
{
    public function __construct(
        public readonly int $sourceLayerId,
        public readonly string $qty,
        public readonly ?int $destinationLayerId = null,
    ) {}
}
