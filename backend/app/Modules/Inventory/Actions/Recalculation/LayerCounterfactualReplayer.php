<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\Movements\LayerAllocator;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only FIFO/SPECIFIC replay, internal to recalculation Actions. Movement
 * time controls physical availability; received_at controls FIFO age. Refuses
 * incomplete ledgers and QA/shortage histories whose rules cannot be replayed.
 */
final class LayerCounterfactualReplayer
{
    public function __construct(private readonly LayerAllocator $layers) {}

    public function replay(
        Item $item,
        Location $location,
        ?StockBatch $batch,
        ?string $serialNo,
        BigDecimal $proposedQty,
        BigDecimal $proposedUnitCost,
        CarbonInterface $movedAt,
    ): ImpactPreview {
        return $this->plan($item, $location, $batch, $serialNo, $proposedQty, $proposedUnitCost, $movedAt)->preview;
    }

    public function plan(
        Item $item,
        Location $location,
        ?StockBatch $batch,
        ?string $serialNo,
        BigDecimal $proposedQty,
        BigDecimal $proposedUnitCost,
        CarbonInterface $movedAt,
    ): LayerReplayPlan {
        [$allLayers, $arrivals, $queue] = $this->reconstructAsOf($item, $location, $batch, $serialNo, $movedAt);

        $newLayer = new StockLayer([
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty_received' => (string) $proposedQty, 'qty_remaining' => (string) $proposedQty,
            'base_unit_cost' => (string) $proposedUnitCost, 'received_at' => $movedAt,
            'batch_id' => $batch?->id, 'serial_no' => $serialNo,
        ]);
        $newLayer->id = PHP_INT_MAX;
        $queue->push($newLayer);
        [$queue, $allocationsByMove] = $this->walkLaterEvents($allLayers, $arrivals, $movedAt, $queue);

        [$originalQty, $originalValue] = $this->aggregate($allLayers);
        [$counterfactualQty, $counterfactualValue] = $this->aggregate($queue);

        $preview = new ImpactPreview(
            pool: null,
            originalQtyOnHand: $originalQty,
            originalInventoryValue: $originalValue,
            originalAvgUnitCost: $originalQty->isZero() ? BigDecimal::zero() : $originalValue->dividedBy($originalQty, 4, RoundingMode::HALF_UP),
            counterfactualQtyOnHand: $counterfactualQty,
            counterfactualInventoryValue: $counterfactualValue,
            counterfactualAvgUnitCost: $counterfactualQty->isZero() ? BigDecimal::zero() : $counterfactualValue->dividedBy($counterfactualQty, 4, RoundingMode::HALF_UP),
        );

        return new LayerReplayPlan($preview, $allocationsByMove);
    }

    /**
     * Reconstructs a layer group's real state as of a point in time — real
     * layer ids, qty_remaining as it stood then — shared by plan()'s pre-T
     * queue and planOutboundInsertion()'s fresh outbound allocation. Also
     * returns the group's full layer set and their arrival moves, since both
     * callers need them again to walk later events.
     *
     * @return array{0: Collection<int, StockLayer>, 1: array<int, array{at: CarbonInterface, move_id: int}>, 2: Collection<int, StockLayer>}
     */
    public function reconstructAsOf(Item $item, Location $location, ?StockBatch $batch, ?string $serialNo, CarbonInterface $movedAt): array
    {
        if ($this->hasUnsupportedShortage($item, $location, $batch, $movedAt)) {
            throw new DomainInvariantException("This location had negative-stock shortage activity after the proposed date — recalculation isn't yet supported for this case.", 'moved_at');
        }

        $allLayers = $this->groupLayers($item, $location, $batch, $serialNo);
        $arrivals = $this->arrivals($allLayers);
        $this->guardHistory($allLayers);
        $preT = $allLayers->filter(fn (StockLayer $layer): bool => $arrivals[$layer->id]['at']->lte($movedAt))->values();

        $consumedByLayerAtT = $this->consumedUpTo($preT->pluck('id'), $movedAt);
        $queue = $preT->map(function (StockLayer $layer) use ($consumedByLayerAtT): StockLayer {
            $reconstructed = $layer->replicate();
            $reconstructed->id = $layer->id;
            $reconstructed->qty_remaining = (string) BigDecimal::of($layer->qty_received)
                ->minus($consumedByLayerAtT->get($layer->id, BigDecimal::zero()));

            return $reconstructed;
        })->values();

        return [$allLayers, $arrivals, $queue];
    }

    /**
     * The outbound counterpart to plan(): instead of inserting a new layer,
     * this performs a new outbound draw — an approved backdated ISSUE, or
     * the source-side leg of a backdated TRANSFER — against the group's real
     * state as of moved_at, then continues the identical walkLaterEvents()
     * re-walk to find whatever later real moves that draw displaces. Real
     * layer ids throughout, since nothing new is being received — no
     * synthetic-id bridge is needed the way MovementReplayState's preview
     * simulation would need one. The GL/value side is deliberately not
     * computed here: MovementCounterfactualReplayer's already-approved,
     * already-revalidated `valuation_adjustment` remains the sole source for
     * that; this method only ever answers "which real layer(s) now".
     *
     * @return array{ownAllocation: Collection<int, array{layer: StockLayer, qty: BigDecimal}>, plan: LayerReplayPlan}
     */
    public function planOutboundInsertion(Item $item, Location $location, ?StockBatch $batch, ?string $serialNo, BigDecimal $qty, CarbonInterface $movedAt): array
    {
        [$allLayers, $arrivals, $queue] = $this->reconstructAsOf($item, $location, $batch, $serialNo, $movedAt);

        $sorted = $queue->sortBy([['received_at', 'asc'], ['id', 'asc']])->values();
        $ownAllocation = $this->layers->allocate($sorted, $qty);
        $allocated = $ownAllocation->reduce(fn (BigDecimal $sum, array $a): BigDecimal => $sum->plus($a['qty']), BigDecimal::zero());
        if (! $allocated->isEqualTo($qty)) {
            throw new DomainInvariantException('The live ledger no longer has physical cover for this backdated movement.', 'moved_at');
        }
        foreach ($ownAllocation as $allocation) {
            $allocation['layer']->qty_remaining = (string) BigDecimal::of($allocation['layer']->qty_remaining)->minus($allocation['qty']);
        }

        [$queue, $allocationsByMove] = $this->walkLaterEvents($allLayers, $arrivals, $movedAt, $queue);

        [$originalQty, $originalValue] = $this->aggregate($allLayers);
        [$counterfactualQty, $counterfactualValue] = $this->aggregate($queue);

        $preview = new ImpactPreview(
            pool: null,
            originalQtyOnHand: $originalQty,
            originalInventoryValue: $originalValue,
            originalAvgUnitCost: $originalQty->isZero() ? BigDecimal::zero() : $originalValue->dividedBy($originalQty, 4, RoundingMode::HALF_UP),
            counterfactualQtyOnHand: $counterfactualQty,
            counterfactualInventoryValue: $counterfactualValue,
            counterfactualAvgUnitCost: $counterfactualQty->isZero() ? BigDecimal::zero() : $counterfactualValue->dividedBy($counterfactualQty, 4, RoundingMode::HALF_UP),
        );

        return ['ownAllocation' => $ownAllocation, 'plan' => new LayerReplayPlan($preview, $allocationsByMove)];
    }

    /**
     * The destination-side counterpart to plan(): a transfer's own corrected
     * source-side allocation (computed elsewhere, by FifoReceiptReallocator)
     * implies its destination layer(s) arrived with different cost/age than
     * what was actually posted. This reconstructs the destination group's
     * real timeline exactly like plan() does, but instead of inserting one
     * brand-new future layer, it caps each superseded destination
     * layer-component's contribution by its invalidated quantity and inserts
     * the corrected replacement component(s) at the same transfer arrival,
     * then re-walks later events identically. Internal to ExecuteRecalculation
     * via FifoReceiptReallocator; never called directly by preview/approval.
     *
     * $supersededComponents is keyed by the existing destination StockLayer id
     * being partly/fully superseded. $newComponents is keyed by an arbitrary
     * sentinel id (never a real layer id) — the corrected replacement
     * component(s) arriving at the same transfer.
     *
     * @param  Collection<int, array{layer: StockLayer, invalidatedQty: BigDecimal}>  $supersededComponents
     * @param  Collection<int, array{qty: BigDecimal, baseUnitCost: BigDecimal, receivedAt: CarbonInterface}>  $newComponents
     */
    public function planTransferCascade(
        Item $item,
        Location $destination,
        ?StockBatch $batch,
        ?string $serialNo,
        StockMove $transferMove,
        Collection $supersededComponents,
        Collection $newComponents,
    ): LayerReplayPlan {
        $movedAt = Carbon::parse($transferMove->moved_at);
        if ($this->hasUnsupportedShortage($item, $destination, $batch, $movedAt)) {
            throw new DomainInvariantException("This location had negative-stock shortage activity after the proposed date — recalculation isn't yet supported for this case.", 'moved_at');
        }

        $allLayers = $this->groupLayers($item, $destination, $batch, $serialNo);
        $arrivals = $this->arrivals($allLayers);
        $this->guardHistory($allLayers);
        $preT = $allLayers->filter(fn (StockLayer $layer): bool => $arrivals[$layer->id]['at']->lte($movedAt))->values();

        $consumedByLayerAtT = $this->consumedUpTo($preT->pluck('id'), $movedAt);
        $queue = $preT->map(function (StockLayer $layer) use ($consumedByLayerAtT, $supersededComponents): StockLayer {
            $reconstructed = $layer->replicate();
            $reconstructed->id = $layer->id;
            $remaining = BigDecimal::of($layer->qty_received)->minus($consumedByLayerAtT->get($layer->id, BigDecimal::zero()));
            $superseded = $supersededComponents->get($layer->id);
            if ($superseded !== null) {
                $remaining = $remaining->minus($superseded['invalidatedQty']);
                if ($remaining->isNegative()) {
                    throw new DomainInvariantException('The live transfer destination ledger no longer matches the approved replay.', 'moved_at');
                }
            }
            $reconstructed->qty_remaining = (string) $remaining;

            return $reconstructed;
        })->values();

        foreach ($newComponents as $sentinelId => $component) {
            $inserted = new StockLayer([
                'item_id' => $item->id, 'location_id' => $destination->id,
                'qty_received' => (string) $component['qty'], 'qty_remaining' => (string) $component['qty'],
                'base_unit_cost' => (string) $component['baseUnitCost'], 'received_at' => $component['receivedAt'],
                'batch_id' => $batch?->id, 'serial_no' => $serialNo,
            ]);
            $inserted->id = $sentinelId;
            $queue->push($inserted);
        }

        [$queue, $allocationsByMove] = $this->walkLaterEvents($allLayers, $arrivals, $movedAt, $queue);

        [$originalQty, $originalValue] = $this->aggregate($allLayers);
        [$counterfactualQty, $counterfactualValue] = $this->aggregate($queue);

        $preview = new ImpactPreview(
            pool: null,
            originalQtyOnHand: $originalQty,
            originalInventoryValue: $originalValue,
            originalAvgUnitCost: $originalQty->isZero() ? BigDecimal::zero() : $originalValue->dividedBy($originalQty, 4, RoundingMode::HALF_UP),
            counterfactualQtyOnHand: $counterfactualQty,
            counterfactualInventoryValue: $counterfactualValue,
            counterfactualAvgUnitCost: $counterfactualQty->isZero() ? BigDecimal::zero() : $counterfactualValue->dividedBy($counterfactualQty, 4, RoundingMode::HALF_UP),
        );

        return new LayerReplayPlan($preview, $allocationsByMove);
    }

    /**
     * Shared later-events walker for plan() and planTransferCascade(): given a
     * reconstructed pre-T queue, replays every later supply/reversal/consume
     * event identically regardless of what was inserted before T.
     *
     * @param  Collection<int, StockLayer>  $allLayers
     * @param  array<int, array{at: CarbonInterface, move_id: int}>  $arrivals
     * @param  Collection<int, StockLayer>  $queue
     * @return array{0: Collection<int, StockLayer>, 1: Collection<int, Collection<int, array{layer_id:int, qty:BigDecimal}>>}
     */
    private function walkLaterEvents(Collection $allLayers, array $arrivals, CarbonInterface $movedAt, Collection $queue): array
    {
        $allocationsByMove = collect();
        foreach ($this->laterEvents($allLayers, $arrivals, $movedAt) as $event) {
            if ($event['type'] === 'supply') {
                $fresh = $event['layer']->replicate();
                $fresh->id = $event['layer']->id;
                $fresh->qty_remaining = $event['layer']->qty_received;
                $queue->push($fresh);

                continue;
            }
            if ($event['type'] === 'reversal') {
                $layer = $queue->firstWhere('id', $event['layer_id']);
                if ($layer === null || BigDecimal::of($layer->qty_remaining)->isLessThan($event['qty'])) {
                    throw new DomainInvariantException('The counterfactual consumes stock needed by a receipt reversal.', 'moved_at');
                }
                $layer->qty_remaining = (string) BigDecimal::of($layer->qty_remaining)->minus($event['qty']);

                continue;
            }
            // A transfer may arrive later while carrying an older FIFO age.
            $queue = $queue->sortBy([['received_at', 'asc'], ['id', 'asc']])->values();
            $allocations = $this->layers->allocate($queue, $event['qty']);
            $allocated = $allocations->reduce(fn (BigDecimal $sum, array $a): BigDecimal => $sum->plus($a['qty']), BigDecimal::zero());
            if (! $allocated->isEqualTo($event['qty'])) {
                throw new DomainInvariantException('The counterfactual cannot cover a historical consumption.', 'moved_at');
            }
            foreach ($allocations as $allocation) {
                $allocatedLayer = $allocation['layer'];
                $allocatedLayer->qty_remaining = (string) BigDecimal::of($allocatedLayer->qty_remaining)->minus($allocation['qty']);
            }
            $allocationsByMove->put((int) $event['move_id'], $allocations->map(fn (array $allocation): array => [
                'layer_id' => (int) $allocation['layer']->id,
                'qty' => $allocation['qty'],
            ])->values());
        }

        return [$queue, $allocationsByMove];
    }

    /** @return Collection<int, StockLayer> */
    private function groupLayers(Item $item, Location $location, ?StockBatch $batch, ?string $serialNo): Collection
    {
        return StockLayer::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)
            ->when($batch === null, fn ($query) => $query->whereNull('batch_id'), fn ($query) => $query->where('batch_id', $batch->id))
            ->when($serialNo === null, fn ($query) => $query->whereNull('serial_no'), fn ($query) => $query->where('serial_no', $serialNo))
            ->orderBy('received_at')->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, int>  $layerIds
     * @return Collection<int, BigDecimal> keyed by stock_layer_id
     */
    private function consumedUpTo(Collection $layerIds, CarbonInterface $movedAt): Collection
    {
        if ($layerIds->isEmpty()) {
            return collect();
        }

        return DB::table('stock_layer_consumptions')->where('stock_layer_consumptions.company_id', app(CompanyContext::class)->id())
            ->whereIn('stock_layer_id', $layerIds)
            ->join('stock_moves', 'stock_moves.id', '=', 'stock_layer_consumptions.stock_move_id')
            ->where('stock_moves.moved_at', '<=', $movedAt)
            ->select('stock_layer_consumptions.stock_layer_id', 'stock_layer_consumptions.qty_consumed', 'stock_layer_consumptions.purpose')
            ->get()
            ->groupBy('stock_layer_id')
            ->map(fn (Collection $rows): BigDecimal => $rows->reduce(
                fn (BigDecimal $total, $row): BigDecimal => $row->purpose === 'ALLOCATION_REVERSAL'
                    ? $total->minus(BigDecimal::of($row->qty_consumed))
                    : $total->plus(BigDecimal::of($row->qty_consumed)),
                BigDecimal::zero(),
            ));
    }

    /**
     * @param  Collection<int, StockLayer>  $layers
     * @return array<int, array{at: CarbonInterface, move_id: int}>
     */
    private function arrivals(Collection $layers): array
    {
        $moves = StockMove::query()->whereIn('source_type', $layers->pluck('source_type'))
            ->whereIn('source_id', $layers->pluck('source_id'))->get()
            ->keyBy(fn (StockMove $move): string => $move->source_type.':'.$move->source_id);

        return $layers->mapWithKeys(function (StockLayer $layer) use ($moves): array {
            $move = $moves->get($layer->source_type.':'.$layer->source_id);
            if ($move === null) {
                throw new DomainInvariantException('A layer has no recorded arrival movement.', 'moved_at');
            }

            return [$layer->id => ['at' => Carbon::parse($move->moved_at), 'move_id' => $move->id]];
        })->all();
    }

    /** Refuse missing pre-ledger depletion and present-day QA masquerading as history.
     * @param  Collection<int, StockLayer>  $layers
     */
    private function guardHistory(Collection $layers): void
    {
        $totals = DB::table('stock_layer_consumptions')->where('stock_layer_consumptions.company_id', app(CompanyContext::class)->id())->whereIn('stock_layer_id', $layers->pluck('id'))
            ->selectRaw("stock_layer_id, SUM(CASE WHEN purpose = 'ALLOCATION_REVERSAL' THEN -qty_consumed ELSE qty_consumed END) AS qty")
            ->groupBy('stock_layer_id')->pluck('qty', 'stock_layer_id');
        foreach ($layers as $layer) {
            if (! BigDecimal::of($layer->qty_received)->minus($totals->get($layer->id, '0'))->isEqualTo($layer->qty_remaining)) {
                throw new DomainInvariantException('Layer history is incomplete or includes shortage settlement; replay cannot reconstruct it safely.', 'moved_at');
            }
        }
        $receiptIds = $layers->where('source_type', 'GOODS_RECEIPT_LINE')->pluck('source_id');
        if (GoodsReceiptLine::query()->whereIn('id', $receiptIds)->whereHas('purchaseOrderLine', fn ($q) => $q->where('qa_required', true))->exists()) {
            throw new DomainInvariantException('Historical QA availability is not yet supported by recalculation.', 'moved_at');
        }
    }

    /**
     * @param  Collection<int, StockLayer>  $allLayers
     * @param  array<int, array{at: CarbonInterface, move_id: int}>  $arrivals
     * @return Collection<int, array{type: string, at: CarbonInterface, move_id: int, layer?: StockLayer, layer_id?: int, qty?: BigDecimal}>
     */
    private function laterEvents(Collection $allLayers, array $arrivals, CarbonInterface $movedAt): Collection
    {
        $events = collect();
        foreach ($allLayers as $layer) {
            $arrival = $arrivals[$layer->id];
            if ($arrival['at']->gt($movedAt)) {
                $events->push(['type' => 'supply', 'at' => $arrival['at'], 'move_id' => $arrival['move_id'], 'layer' => $layer]);
            }
        }
        $rows = DB::table('stock_layer_consumptions')->where('stock_layer_consumptions.company_id', app(CompanyContext::class)->id())
            ->whereIn('stock_layer_id', $allLayers->pluck('id'))
            ->join('stock_moves', 'stock_moves.id', '=', 'stock_layer_consumptions.stock_move_id')
            ->where('stock_moves.moved_at', '>', $movedAt)
            ->select('stock_layer_consumptions.*', 'stock_moves.moved_at')->get();
        foreach ($rows->groupBy('stock_move_id') as $moveId => $consumptions) {
            // A genuine receipt reversal always names exactly one layer, but a
            // transfer-destination provenance correction can supersede more
            // than one component of the same move at once — each must stay
            // its own exact-layer event rather than collapsing into one.
            if ($consumptions->every(fn ($row): bool => $row->purpose === 'REVERSAL')) {
                foreach ($consumptions->groupBy('stock_layer_id') as $layerId => $layerRows) {
                    $qty = $layerRows->reduce(fn (BigDecimal $sum, $row): BigDecimal => $sum->plus(BigDecimal::of($row->qty_consumed)), BigDecimal::zero());
                    if ($qty->isZero()) {
                        continue;
                    }
                    $events->push([
                        'type' => 'reversal', 'at' => Carbon::parse($layerRows->first()->moved_at), 'move_id' => $moveId,
                        'layer_id' => (int) $layerId, 'qty' => $qty,
                    ]);
                }

                continue;
            }
            $qty = $consumptions->reduce(
                fn (BigDecimal $sum, $row): BigDecimal => $row->purpose === 'ALLOCATION_REVERSAL'
                    ? $sum->minus(BigDecimal::of($row->qty_consumed))
                    : $sum->plus(BigDecimal::of($row->qty_consumed)),
                BigDecimal::zero(),
            );
            if ($qty->isZero()) {
                continue;
            }
            $events->push([
                'type' => 'consume',
                'at' => Carbon::parse($consumptions->first()->moved_at), 'move_id' => $moveId,
                'layer_id' => $consumptions->first()->stock_layer_id,
                'qty' => $qty,
            ]);
        }

        return $events->sortBy([['at', 'asc'], ['move_id', 'asc']])->values();
    }

    /**
     * @param  Collection<int, StockLayer>  $layers
     * @return array{0: BigDecimal, 1: BigDecimal} qty, value
     */
    private function aggregate(Collection $layers): array
    {
        $qty = $layers->reduce(fn (BigDecimal $total, StockLayer $layer): BigDecimal => $total->plus(BigDecimal::of($layer->qty_remaining)), BigDecimal::zero())
            ->toScale(4, RoundingMode::HALF_UP);
        $value = $layers->reduce(
            fn (BigDecimal $total, StockLayer $layer): BigDecimal => $total->plus(BigDecimal::of($layer->qty_remaining)->multipliedBy(BigDecimal::of($layer->base_unit_cost))),
            BigDecimal::zero(),
        )->toScale(4, RoundingMode::HALF_UP);

        return [$qty, $value];
    }

    private function hasUnsupportedShortage(Item $item, Location $location, ?StockBatch $batch, CarbonInterface $movedAt): bool
    {
        return NegativeStockShortage::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)
            ->when($batch === null, fn ($query) => $query->whereNull('batch_id'), fn ($query) => $query->where('batch_id', $batch->id))
            ->where(function ($scope) use ($movedAt): void {
                $scope->where('qty_open', '>', 0)
                    ->orWhereHas('settlements', fn ($q) => $q->whereHas('inboundMove', fn ($m) => $m->where('moved_at', '>=', $movedAt)))
                    ->orWhereExists(function ($query) use ($movedAt): void {
                        $query->select(DB::raw(1))->from('stock_moves')
                            ->whereColumn('stock_moves.id', 'negative_stock_shortages.source_move_id')
                            ->where('stock_moves.moved_at', '>', $movedAt);
                    });
            })
            ->exists();
    }
}
