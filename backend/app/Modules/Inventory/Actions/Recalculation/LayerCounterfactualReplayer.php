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
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Computes what a FIFO or SPECIFIC layer group's remaining value would be if a
 * proposed receipt had been posted at its true chronological position (T) —
 * see docs/domain/stock-recalculation.md. Pure/read-only: no locks, no writes,
 * no layer is ever saved. One class covers both methods: SPECIFIC is FIFO
 * with exactly one always-available layer of qty 1, so a brand-new serial
 * naturally degenerates to zero impact — nothing else can reference a serial
 * that didn't exist yet — with no special-cased branch needed.
 *
 * Reuses the real, unmodified LayerAllocator::allocate() to re-derive which
 * layer(s) each later issue/transfer-out would have drawn from — the FIFO
 * analogue of AvcoCounterfactualReplayer reusing AvcoPoolManager's averaging
 * formula: one selection algorithm, not two that can quietly drift apart.
 *
 * Deliberately refuses (DomainInvariantException) rather than guess whenever
 * the group had any negative-stock shortage activity in the replay window —
 * same boundary AvcoCounterfactualReplayer draws, same reasoning.
 *
 * A transfer's destination side is out of scope: it's treated as an ordinary
 * outbound consumption from this group (stock_layer_consumptions already
 * records it that way), with no cascade into the destination location's own
 * subsequent history — the same single-pool boundary the AVCO replayer draws
 * for a transfer's other side.
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
        if ($this->hasShortageCreatedAfter($item, $location, $batch, $movedAt)) {
            throw new DomainInvariantException("This location had negative-stock shortage activity after the proposed date — recalculation isn't yet supported for this case.", 'moved_at');
        }

        $allLayers = $this->groupLayers($item, $location, $batch, $serialNo);
        $preT = $allLayers->filter(fn (StockLayer $layer): bool => $layer->received_at->lte($movedAt))->values();

        $consumedByLayerAtT = $this->consumedUpTo($preT->pluck('id'), $movedAt);
        $queue = $preT->map(function (StockLayer $layer) use ($consumedByLayerAtT): StockLayer {
            $reconstructed = $layer->replicate();
            $reconstructed->id = $layer->id;
            $reconstructed->qty_remaining = (string) BigDecimal::of($layer->qty_received)
                ->minus($consumedByLayerAtT->get($layer->id, BigDecimal::zero()));

            return $reconstructed;
        })->values();

        $newLayer = new StockLayer([
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty_received' => (string) $proposedQty, 'qty_remaining' => (string) $proposedQty,
            'base_unit_cost' => (string) $proposedUnitCost, 'received_at' => $movedAt,
            'batch_id' => $batch?->id, 'serial_no' => $serialNo,
        ]);
        $queue->push($newLayer);

        foreach ($this->laterEvents($allLayers, $movedAt) as $event) {
            if ($event['type'] === 'supply') {
                $fresh = $event['layer']->replicate();
                $fresh->id = $event['layer']->id;
                $fresh->qty_remaining = $event['layer']->qty_received;
                $queue->push($fresh);

                continue;
            }
            $allocations = $this->layers->allocate($queue, $event['qty']);
            foreach ($allocations as $allocation) {
                $allocatedLayer = $allocation['layer'];
                $allocatedLayer->qty_remaining = (string) BigDecimal::of($allocatedLayer->qty_remaining)->minus($allocation['qty']);
            }
        }

        [$originalQty, $originalValue] = $this->aggregate($allLayers);
        [$counterfactualQty, $counterfactualValue] = $this->aggregate($queue);

        return new ImpactPreview(
            pool: null,
            originalQtyOnHand: $originalQty,
            originalInventoryValue: $originalValue,
            originalAvgUnitCost: $originalQty->isZero() ? BigDecimal::zero() : $originalValue->dividedBy($originalQty, 4, RoundingMode::HALF_UP),
            counterfactualQtyOnHand: $counterfactualQty,
            counterfactualInventoryValue: $counterfactualValue,
            counterfactualAvgUnitCost: $counterfactualQty->isZero() ? BigDecimal::zero() : $counterfactualValue->dividedBy($counterfactualQty, 4, RoundingMode::HALF_UP),
        );
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

        return DB::table('stock_layer_consumptions')
            ->whereIn('stock_layer_id', $layerIds)
            ->join('stock_moves', 'stock_moves.id', '=', 'stock_layer_consumptions.stock_move_id')
            ->where('stock_moves.moved_at', '<=', $movedAt)
            ->select('stock_layer_consumptions.stock_layer_id', 'stock_layer_consumptions.qty_consumed')
            ->get()
            ->groupBy('stock_layer_id')
            ->map(fn (Collection $rows): BigDecimal => $rows->reduce(
                fn (BigDecimal $total, $row): BigDecimal => $total->plus(BigDecimal::of($row->qty_consumed)),
                BigDecimal::zero(),
            ));
    }

    /**
     * Merges two later-event sources into one chronological sequence: a
     * post-T layer arriving (a receipt or a transfer-in — stock_layers
     * doesn't distinguish, and neither needs to here) adds fresh supply;
     * a later move's consumption rows in this group (an issue, or a
     * transfer's source side) re-allocate that move's original total qty.
     *
     * @param  Collection<int, StockLayer>  $allLayers
     * @return Collection<int, array{type:string, at:CarbonInterface, layer?:StockLayer, qty?:BigDecimal}>
     */
    private function laterEvents(Collection $allLayers, CarbonInterface $movedAt): Collection
    {
        $events = collect();
        foreach ($allLayers->filter(fn (StockLayer $layer): bool => $layer->received_at->gt($movedAt)) as $layer) {
            $events->push(['type' => 'supply', 'at' => $layer->received_at, 'layer' => $layer]);
        }

        $consumptionsByMove = DB::table('stock_layer_consumptions')
            ->whereIn('stock_layer_id', $allLayers->pluck('id'))
            ->join('stock_moves', 'stock_moves.id', '=', 'stock_layer_consumptions.stock_move_id')
            ->where('stock_moves.moved_at', '>', $movedAt)
            ->select('stock_layer_consumptions.qty_consumed', 'stock_moves.id as move_id', 'stock_moves.moved_at as move_moved_at')
            ->get()
            ->groupBy('move_id');
        foreach ($consumptionsByMove as $rows) {
            $qty = $rows->reduce(fn (BigDecimal $total, $row): BigDecimal => $total->plus(BigDecimal::of($row->qty_consumed)), BigDecimal::zero());
            $events->push(['type' => 'consume', 'at' => Carbon::parse($rows->first()->move_moved_at), 'qty' => $qty]);
        }

        return $events->sortBy('at')->values();
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

    private function hasShortageCreatedAfter(Item $item, Location $location, ?StockBatch $batch, CarbonInterface $movedAt): bool
    {
        return NegativeStockShortage::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)
            ->when($batch === null, fn ($query) => $query->whereNull('batch_id'), fn ($query) => $query->where('batch_id', $batch->id))
            ->whereExists(function ($query) use ($movedAt): void {
                $query->select(DB::raw(1))->from('stock_moves')
                    ->whereColumn('stock_moves.id', 'negative_stock_shortages.source_move_id')
                    ->where('stock_moves.moved_at', '>', $movedAt);
            })
            ->exists();
    }
}
