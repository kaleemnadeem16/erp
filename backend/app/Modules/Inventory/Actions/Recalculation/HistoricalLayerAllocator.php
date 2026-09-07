<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\Movements\LayerAllocator;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * FIFO/SPECIFIC allocation against real layers as they stood at a past
 * moment, for posting an approved backdated issue/transfer whose replay
 * proved nothing later needs correcting (ExecuteRecalculation's clean-replay
 * case only). Reuses LayerCounterfactualReplayer::reconstructAsOf() for the
 * same real-id, real-qty_remaining-at-T reconstruction plan() already relies
 * on, then the same production LayerAllocator::allocate() every live posting
 * path uses — no synthetic layers, no future re-walk, since the caller has
 * already confirmed nothing downstream depends on this exact split.
 */
final class HistoricalLayerAllocator
{
    public function __construct(
        private readonly LayerCounterfactualReplayer $replayer,
        private readonly LayerAllocator $layers,
    ) {}

    /** @return Collection<int, array{layer: StockLayer, qty: BigDecimal}> */
    public function allocate(Item $item, Location $location, ?StockBatch $batch, ?string $serialNo, BigDecimal $qty, CarbonInterface $movedAt): Collection
    {
        [, , $queue] = $this->replayer->reconstructAsOf($item, $location, $batch, $serialNo, $movedAt);
        $sorted = $queue->sortBy([['received_at', 'asc'], ['id', 'asc']])->values();
        $allocations = $this->layers->allocate($sorted, $qty);
        $allocated = $allocations->reduce(fn (BigDecimal $sum, array $a): BigDecimal => $sum->plus($a['qty']), BigDecimal::zero());
        if (! $allocated->isEqualTo($qty)) {
            throw new DomainInvariantException('The live ledger no longer has physical cover for this backdated movement.', 'moved_at');
        }

        return $allocations;
    }
}
