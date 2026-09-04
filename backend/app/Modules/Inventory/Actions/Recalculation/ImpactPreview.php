<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Modules\Inventory\Models\AvcoPool;
use Brick\Math\BigDecimal;

/**
 * One counterfactual-replayer result — AvcoCounterfactualReplayer or
 * LayerCounterfactualReplayer — the item's live state versus what it would be
 * if the proposed receipt had been posted at its true chronological position.
 * valuationAdjustment is the exact delta a correction would need to apply —
 * counterfactualInventoryValue minus originalInventoryValue. pool is set for
 * AVCO and null for FIFO/SPECIFIC, which has no pool concept — identified
 * instead by (item, location, batch/serial), already on the parent request.
 */
final readonly class ImpactPreview
{
    public function __construct(
        public ?AvcoPool $pool,
        public BigDecimal $originalQtyOnHand,
        public BigDecimal $originalInventoryValue,
        public BigDecimal $originalAvgUnitCost,
        public BigDecimal $counterfactualQtyOnHand,
        public BigDecimal $counterfactualInventoryValue,
        public BigDecimal $counterfactualAvgUnitCost,
    ) {}

    public function valuationAdjustment(): BigDecimal
    {
        return $this->counterfactualInventoryValue->minus($this->originalInventoryValue);
    }
}
