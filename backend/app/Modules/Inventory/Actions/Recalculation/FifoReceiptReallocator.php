<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockLayerConsumption;
use App\Modules\Inventory\Models\StockMove;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Applies an approved FIFO recalculation replay's downstream corrections.
 * Internal to ExecuteRecalculation and must run inside that Action's locked
 * transaction. Two entry points share the same correction engine below:
 * apply() for a backdated receipt (a newly minted layer other moves may now
 * draw from) and applyCorrections() for a backdated ISSUE/TRANSFER's own
 * outbound insertion (no new layer — displaced draws land on an existing
 * real layer instead, since nothing new is being received).
 *
 * A later direct issue is corrected in place: release its stale ALLOCATION,
 * append a replacement. A later transfer is corrected one level deeper — its
 * own source-side draw is corrected the same way, then the destination
 * layer-component(s) it delivered are superseded by freshly minted ones
 * carrying the corrected cost/age (the old components stay, immutable, with
 * a purpose = REVERSAL row accounting for whatever part of them no longer
 * happened), and this same reallocation runs again, recursively, against
 * whatever the destination's own later issues — or its own later transfers,
 * cascading through however many hops real history actually contains —
 * already consumed from them. Recursion is bounded by real history alone
 * (each hop strictly follows a later stock_moves.moved_at than the one
 * before it).
 */
final class FifoReceiptReallocator
{
    public const PROPOSED_LAYER_ID = PHP_INT_MAX;

    public function __construct(private readonly LayerCounterfactualReplayer $layerReplay) {}

    public function apply(RecalculationRequest $request, StockLayer $newLayer, LayerReplayPlan $plan): void
    {
        $this->applyToGroup($request, collect([self::PROPOSED_LAYER_ID => $newLayer]), $plan, 0);
    }

    /**
     * A backdated ISSUE/TRANSFER's own outbound draw needs no new layer —
     * every displaced allocation this plan names is already a real,
     * pre-existing layer (LayerCounterfactualReplayer::planOutboundInsertion()
     * builds it that way), so the sentinel map stays empty.
     */
    public function applyCorrections(RecalculationRequest $request, LayerReplayPlan $plan): void
    {
        $this->applyToGroup($request, collect(), $plan, 0);
    }

    /** @param  Collection<int, StockLayer>  $newLayersBySentinel */
    private function applyToGroup(RecalculationRequest $request, Collection $newLayersBySentinel, LayerReplayPlan $plan, int $depth): void
    {
        $moveIds = $plan->allocationsByMove->keys()->map(fn ($id): int => (int) $id)->all();
        $moves = StockMove::query()->whereIn('id', $moveIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $rows = StockLayerConsumption::query()->whereIn('stock_move_id', $moveIds)
            ->orderBy('id')->lockForUpdate()->get();
        $allocationRows = $rows->where('purpose', 'ALLOCATION');
        $reversedBySource = $rows->where('purpose', 'ALLOCATION_REVERSAL')->groupBy('reverses_consumption_id')
            ->map(fn (Collection $reversals): BigDecimal => $reversals->reduce(
                fn (BigDecimal $sum, StockLayerConsumption $row): BigDecimal => $sum->plus($row->qty_consumed),
                BigDecimal::zero(),
            ));

        $activeRows = $allocationRows->map(function (StockLayerConsumption $row) use ($reversedBySource): array {
            return [
                'row' => $row,
                'qty' => BigDecimal::of($row->qty_consumed)->minus($reversedBySource->get($row->id, BigDecimal::zero())),
            ];
        })->filter(fn (array $entry): bool => $entry['qty']->isGreaterThan(0))->values();

        $current = $activeRows->groupBy(fn (array $entry): string => $this->key($entry['row']->stock_move_id, $entry['row']->stock_layer_id))
            ->map(fn (Collection $entries): BigDecimal => $entries->reduce(
                fn (BigDecimal $sum, array $entry): BigDecimal => $sum->plus($entry['qty']),
                BigDecimal::zero(),
            ));
        $desired = collect();
        foreach ($plan->allocationsByMove as $moveId => $allocations) {
            foreach ($allocations as $allocation) {
                $layerId = $newLayersBySentinel->has($allocation['layer_id'])
                    ? $newLayersBySentinel->get($allocation['layer_id'])->id
                    : $allocation['layer_id'];
                $key = $this->key((int) $moveId, $layerId);
                $desired->put($key, BigDecimal::of($desired->get($key, BigDecimal::zero()))->plus($allocation['qty']));
            }
        }

        $keys = $current->keys()->merge($desired->keys())->unique()->sort()->values();
        $decreases = collect();
        $increases = collect();
        foreach ($keys as $key) {
            $delta = BigDecimal::of($desired->get($key, BigDecimal::zero()))
                ->minus($current->get($key, BigDecimal::zero()));
            if ($delta->isLessThan(0)) {
                $decreases->put($key, $delta->negated());
            } elseif ($delta->isGreaterThan(0)) {
                $increases->put($key, $delta);
            }
        }

        $changedMoveIds = $decreases->keys()->merge($increases->keys())
            ->map(fn (string $key): int => (int) explode(':', $key, 2)[0])->unique();
        $unsupported = $changedMoveIds->filter(fn (int $moveId): bool => ! in_array($moves->get($moveId)?->movement_kind, ['ISSUE', 'TRANSFER'], true));
        if ($unsupported->isNotEmpty()) {
            throw new DomainInvariantException('FIFO execution through legacy outbound history is not supported yet.', 'item_id');
        }
        $transferMoveIds = $changedMoveIds->filter(fn (int $moveId): bool => $moves->get($moveId)?->movement_kind === 'TRANSFER');

        $layerIds = $keys->map(fn (string $key): int => (int) explode(':', $key, 2)[1])->unique()->sort()->values();
        $layers = StockLayer::query()->whereIn('id', $layerIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Release every no-longer-correct allocation before consuming any
        // replacement, so stock moving from a later issue to an earlier one is
        // available independent of iteration order.
        foreach ($decreases as $key => $qtyToReverse) {
            [$moveId, $layerId] = array_map('intval', explode(':', $key, 2));
            $remaining = $qtyToReverse;
            foreach ($activeRows->filter(fn (array $entry): bool => $entry['row']->stock_move_id === $moveId && $entry['row']->stock_layer_id === $layerId) as $entry) {
                $take = $entry['qty']->isLessThan($remaining) ? $entry['qty'] : $remaining;
                StockLayerConsumption::query()->create([
                    'stock_move_id' => $moveId,
                    'stock_layer_id' => $layerId,
                    'qty_consumed' => (string) $take,
                    'unit_cost' => $entry['row']->unit_cost,
                    'purpose' => 'ALLOCATION_REVERSAL',
                    'reverses_consumption_id' => $entry['row']->id,
                    'recalculation_request_id' => $request->id,
                ]);
                $remaining = $remaining->minus($take);
                if ($remaining->isZero()) {
                    break;
                }
            }
            if (! $remaining->isZero()) {
                throw new DomainInvariantException('The live FIFO allocation ledger no longer matches the approved replay.', 'item_id');
            }
            $layer = $layers->get($layerId);
            $layer->update(['qty_remaining' => (string) BigDecimal::of($layer->qty_remaining)->plus($qtyToReverse)]);
        }

        foreach ($increases as $key => $qtyToConsume) {
            [$moveId, $layerId] = array_map('intval', explode(':', $key, 2));
            $layer = $layers->get($layerId);
            if ($layer === null || BigDecimal::of($layer->qty_remaining)->isLessThan($qtyToConsume)) {
                throw new DomainInvariantException('The approved FIFO replacement allocation is no longer physically available.', 'item_id');
            }
            StockLayerConsumption::query()->create([
                'stock_move_id' => $moveId,
                'stock_layer_id' => $layerId,
                'qty_consumed' => (string) $qtyToConsume,
                'unit_cost' => $layer->base_unit_cost,
                'purpose' => 'ALLOCATION',
                'recalculation_request_id' => $request->id,
            ]);
            $layer->update(['qty_remaining' => (string) BigDecimal::of($layer->qty_remaining)->minus($qtyToConsume)]);
        }

        foreach ($transferMoveIds as $transferMoveId) {
            $this->cascadeIntoTransferDestination(
                $request, $moves->get($transferMoveId), $allocationRows, $decreases, $increases, $layers, $depth,
            );
        }
    }

    /**
     * A transfer's own source-side draw is already corrected generically
     * above; this corrects what it delivered at the destination. The
     * original ALLOCATION rows for this move (ordered by id) and the
     * transfer's own destination StockLayer rows (ordered by source_line_no)
     * were created from the very same allocation loop in
     * TransferPoster::post(), so they pair up positionally — the k-th
     * allocation is the k-th destination layer component.
     *
     * @param  Collection<int, StockLayerConsumption>  $allocationRows
     * @param  Collection<string, BigDecimal>  $decreases
     * @param  Collection<string, BigDecimal>  $increases
     * @param  Collection<int, StockLayer>  $layers
     */
    private function cascadeIntoTransferDestination(
        RecalculationRequest $request,
        StockMove $transferMove,
        Collection $allocationRows,
        Collection $decreases,
        Collection $increases,
        Collection $layers,
        int $depth,
    ): void {
        $originalAllocations = $allocationRows->where('stock_move_id', $transferMove->id)->sortBy('id')->values();
        $destinationLayers = StockLayer::query()
            ->where('source_type', $transferMove->source_type)->where('source_id', $transferMove->source_id)
            ->orderBy('source_line_no')->lockForUpdate()->get();
        if ($originalAllocations->count() !== $destinationLayers->count()) {
            throw new DomainInvariantException("The transfer's destination layers no longer match its original allocation.", 'item_id');
        }
        $destinationLayerBySourceLayerId = collect();
        foreach ($originalAllocations as $index => $allocation) {
            $destinationLayerBySourceLayerId->put((int) $allocation->stock_layer_id, $destinationLayers[$index]);
        }

        $supersededComponents = collect();
        foreach ($decreases as $key => $qty) {
            [$moveId, $layerId] = array_map('intval', explode(':', $key, 2));
            if ($moveId !== $transferMove->id) {
                continue;
            }
            $destinationLayer = $destinationLayerBySourceLayerId->get($layerId);
            if ($destinationLayer === null) {
                throw new DomainInvariantException("The transfer's destination layers no longer match its original allocation.", 'item_id');
            }
            $supersededComponents->put($destinationLayer->id, ['layer' => $destinationLayer, 'invalidatedQty' => $qty]);
        }

        $newComponents = collect();
        $sentinel = PHP_INT_MAX - 1;
        foreach ($increases as $key => $qty) {
            [$moveId, $layerId] = array_map('intval', explode(':', $key, 2));
            if ($moveId !== $transferMove->id) {
                continue;
            }
            $sourceLayer = $layers->get($layerId);
            $newComponents->put($sentinel, [
                'qty' => $qty, 'baseUnitCost' => BigDecimal::of($sourceLayer->base_unit_cost), 'receivedAt' => $sourceLayer->received_at,
                'sourceLayer' => $sourceLayer,
            ]);
            $sentinel--;
        }

        if ($supersededComponents->isEmpty() && $newComponents->isEmpty()) {
            return;
        }

        $item = $transferMove->item;
        $destination = Location::query()->lockForUpdate()->findOrFail($transferMove->to_location_id);
        $batch = $transferMove->batch_id === null ? null : StockBatch::query()->findOrFail($transferMove->batch_id);
        $serialNo = $transferMove->serial_no;

        $cascadePlan = $this->layerReplay->planTransferCascade(
            $item, $destination, $batch, $serialNo, $transferMove, $supersededComponents, $newComponents,
        );

        $nextLineNo = ((int) $destinationLayers->max('source_line_no')) + 1;
        $mintedBySentinel = collect();
        foreach ($newComponents as $sentinelId => $component) {
            $sourceLayer = $component['sourceLayer'];
            $minted = StockLayer::query()->create([
                'item_id' => $item->id, 'location_id' => $destination->id,
                'qty_received' => (string) $component['qty'], 'qty_remaining' => (string) $component['qty'],
                'unit_cost' => $sourceLayer->unit_cost, 'currency_id' => $sourceLayer->currency_id, 'fx_rate' => $sourceLayer->fx_rate,
                'base_unit_cost' => (string) $component['baseUnitCost'],
                'received_at' => $component['receivedAt'], 'source_type' => $transferMove->source_type, 'source_id' => $transferMove->source_id,
                'source_line_no' => $nextLineNo, 'batch_id' => $batch?->id, 'serial_no' => $serialNo,
            ]);
            $mintedBySentinel->put($sentinelId, $minted);
            $nextLineNo++;
        }

        // Recurse before touching a superseded layer's own qty_remaining: the
        // destination's later moves may already have drawn more from it than
        // the corrected replay leaves valid, and only releasing that
        // downstream draw first guarantees there is room for the subsequent
        // reduction below to never go negative (the qty_received/qty_remaining
        // CHECK constraint would otherwise reject an out-of-order write).
        $this->applyToGroup($request, $mintedBySentinel, $cascadePlan, $depth + 1);

        foreach ($supersededComponents as $destLayerId => $entry) {
            StockLayerConsumption::query()->create([
                'stock_move_id' => $transferMove->id,
                'stock_layer_id' => $destLayerId,
                'qty_consumed' => (string) $entry['invalidatedQty'],
                'unit_cost' => $entry['layer']->base_unit_cost,
                'purpose' => 'REVERSAL',
                'recalculation_request_id' => $request->id,
            ]);
            $entry['layer']->refresh();
            $entry['layer']->update(['qty_remaining' => (string) BigDecimal::of($entry['layer']->qty_remaining)->minus($entry['invalidatedQty'])]);
        }
    }

    private function key(int $moveId, int $layerId): string
    {
        return $moveId.':'.$layerId;
    }
}
