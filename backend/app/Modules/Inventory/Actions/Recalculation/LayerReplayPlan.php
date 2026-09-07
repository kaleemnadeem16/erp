<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/** @internal Immutable output of FIFO/SPECIFIC simulation. */
final readonly class LayerReplayPlan
{
    /**
     * @param  Collection<int, Collection<int, array{layer_id:int, qty:BigDecimal}>>  $allocationsByMove
     */
    public function __construct(
        public ImpactPreview $preview,
        public Collection $allocationsByMove,
    ) {}
}
