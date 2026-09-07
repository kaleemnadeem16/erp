<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockLayerConsumption;
use App\Modules\Inventory\Models\StockMove;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Applies the direct-issue portion of an approved FIFO receipt replay. It is
 * internal to ExecuteRecalculation and must run inside that Action's locked
 * transaction. Transfers are rejected until their immutable destination-layer
 * provenance can be corrected through the same append-only chain.
 */
final class FifoReceiptReallocator
{
    public const PROPOSED_LAYER_ID = PHP_INT_MAX;

    public function apply(RecalculationRequest $request, StockLayer $newLayer, LayerReplayPlan $plan): void
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
                $layerId = $allocation['layer_id'] === self::PROPOSED_LAYER_ID ? $newLayer->id : $allocation['layer_id'];
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
        if ($changedMoveIds->contains(fn (int $moveId): bool => $moves->get($moveId)?->movement_kind !== 'ISSUE')) {
            throw new DomainInvariantException('FIFO execution through transfers or legacy outbound history is not supported yet.', 'item_id');
        }

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
    }

    private function key(int $moveId, int $layerId): string
    {
        return $moveId.':'.$layerId;
    }
}
