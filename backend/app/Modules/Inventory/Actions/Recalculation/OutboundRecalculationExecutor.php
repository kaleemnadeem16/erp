<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Actions\Movements\AvcoPoolManager;
use App\Modules\Inventory\Actions\Movements\LayerAllocator;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockLayerConsumption;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Models\StockTransferDetail;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\RecalculationMovementKind;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Posts an approved backdated ISSUE or TRANSFER for real, for the
 * clean-replay case only (ExecuteRecalculation has already confirmed
 * movement_impact['changed_movements'] is empty — nothing else needs
 * correcting). Mirrors IssuePoster/TransferPoster's posting shape at the
 * request's true historical moved_at instead of today. Internal to
 * ExecuteRecalculation; must run inside that Action's locked transaction.
 * Shortage/override handling is deliberately absent: MovementReplayScope's
 * guard already refuses any history this workflow can reach that involves
 * one.
 *
 * FIFO/SPECIFIC use HistoricalLayerAllocator, since which layer(s) a draw
 * lands on determines its cost and only layers that had actually arrived by
 * the backdated moved_at may be drawn from. AVCO's stock_layers rows are
 * pure traceability — cost comes from the pool, not the layer, so which
 * layer gets physically decremented is inconsequential and there is no
 * consumption ledger to reconstruct historical availability from anyway
 * (recordConsumption() is never called for AVCO). It uses the same live,
 * today's-remaining-layers allocation TransferPoster itself always uses
 * regardless of valuation method.
 */
final class OutboundRecalculationExecutor
{
    public function __construct(
        private readonly HistoricalLayerAllocator $historical,
        private readonly LayerAllocator $layers,
        private readonly AvcoPoolManager $pools,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function post(
        RecalculationRequest $request,
        Item $item,
        Company $company,
        Location $location,
        ?Location $destination,
        ?StockBatch $batch,
        array $attributes,
    ): StockMove {
        $qty = BigDecimal::of($request->qty);
        $proposed = $attributes['movement_impact']['proposed'];
        $baseUnitCost = BigDecimal::of($proposed['base_unit_cost']);
        $baseAmount = BigDecimal::of($proposed['base_amount']);
        $accountId = $item->resolveInventoryAccountId();
        if ($accountId === null) {
            throw new DomainInvariantException('A stock movement requires a resolved inventory account.', 'item_id');
        }

        $allocations = $item->valuation_method === ValuationMethod::Avco
            ? $this->allocateLive($item, $location, $batch, $request->serial_no, $qty)
            : $this->allocateHistorically($item, $location, $batch, $request->serial_no, $qty, $request->moved_at);

        return $request->movement_kind === RecalculationMovementKind::Transfer
            ? $this->postTransfer($request, $item, $company, $location, $destination, $batch, $qty, $baseUnitCost, $baseAmount, $accountId, $allocations, $attributes)
            : $this->postIssue($request, $item, $company, $location, $batch, $qty, $baseUnitCost, $baseAmount, $accountId, $allocations, $attributes);
    }

    /**
     * @param  Collection<int, array{layer: StockLayer, qty: BigDecimal}>  $allocations
     * @param  array<string, mixed>  $attributes
     */
    private function postIssue(
        RecalculationRequest $request, Item $item, Company $company, Location $location, ?StockBatch $batch,
        BigDecimal $qty, BigDecimal $baseUnitCost, BigDecimal $baseAmount, int $accountId, Collection $allocations, array $attributes,
    ): StockMove {
        $pool = $item->valuation_method === ValuationMethod::Avco ? $this->pools->lock($item, $location, $batch) : null;

        $move = StockMove::query()->create([
            'movement_kind' => 'ISSUE',
            'item_id' => $item->id, 'from_location_id' => $location->id, 'to_location_id' => null,
            'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $request->moved_at,
            'source_type' => $request->source_type, 'source_id' => $request->source_id,
            'unit_cost' => (string) $baseUnitCost, 'currency_id' => $company->base_currency_id, 'fx_rate' => '1.000000000',
            'batch_id' => $batch?->id, 'serial_no' => $request->serial_no,
        ]);

        $this->decrementLayers($allocations);
        if ($pool !== null) {
            $sourceScope = $this->scope($item, $location, $batch, $attributes);
            $this->pools->write($pool, BigDecimal::of($sourceScope['qty']), BigDecimal::of($sourceScope['value']));
            $move->valuationEntries()->create([
                'avco_pool_id' => $pool->id, 'pool_effect' => 'SOURCE_OUT', 'qty' => (string) $qty->negated(),
                'unit_cost' => (string) $baseUnitCost, 'amount' => (string) $baseAmount->negated(),
                'base_amount' => (string) $baseAmount->negated(), 'account_id' => $accountId,
            ]);
        } else {
            $this->recordFifoConsumption($move, $allocations);
            foreach ($allocations as $allocation) {
                $amount = $allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))->toScale(4, RoundingMode::HALF_UP);
                $move->valuationEntries()->create([
                    'avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) $allocation['qty']->negated(),
                    'unit_cost' => $allocation['layer']->base_unit_cost, 'amount' => (string) $amount->negated(),
                    'base_amount' => (string) $amount->negated(), 'account_id' => $accountId,
                ]);
            }
        }

        return $move->load('valuationEntries');
    }

    /**
     * @param  Collection<int, array{layer: StockLayer, qty: BigDecimal}>  $allocations
     * @param  array<string, mixed>  $attributes
     */
    private function postTransfer(
        RecalculationRequest $request, Item $item, Company $company, Location $location, ?Location $destination, ?StockBatch $batch,
        BigDecimal $qty, BigDecimal $baseUnitCost, BigDecimal $baseAmount, int $accountId, Collection $allocations, array $attributes,
    ): StockMove {
        [$sourcePool, $destinationPool] = $item->valuation_method === ValuationMethod::Avco
            ? $this->pools->lockPair($item, $location, $destination, $batch)
            : [null, null];

        $move = StockMove::query()->create([
            'movement_kind' => 'TRANSFER',
            'item_id' => $item->id, 'from_location_id' => $location->id, 'to_location_id' => $destination->id,
            'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $request->moved_at,
            'source_type' => $request->source_type, 'source_id' => $request->source_id,
            'unit_cost' => (string) $baseUnitCost, 'currency_id' => $company->base_currency_id, 'fx_rate' => '1.000000000',
            'batch_id' => $batch?->id, 'serial_no' => $request->serial_no,
        ]);

        $this->decrementLayers($allocations);
        $lineNo = 1;
        foreach ($allocations as $allocation) {
            $layer = $allocation['layer'];
            StockLayer::query()->create([
                'item_id' => $item->id, 'location_id' => $destination->id,
                'qty_received' => (string) $allocation['qty'], 'qty_remaining' => (string) $allocation['qty'],
                'unit_cost' => $layer->unit_cost, 'currency_id' => $layer->currency_id, 'fx_rate' => $layer->fx_rate,
                'base_unit_cost' => $sourcePool === null ? $layer->base_unit_cost : (string) $baseUnitCost,
                'received_at' => $layer->received_at, 'source_type' => $request->source_type, 'source_id' => $request->source_id,
                'source_line_no' => $lineNo, 'batch_id' => $batch?->id, 'serial_no' => $request->serial_no,
            ]);
            $lineNo++;
        }

        if ($sourcePool !== null) {
            $sourceScope = $this->scope($item, $location, $batch, $attributes);
            $this->pools->write($sourcePool, BigDecimal::of($sourceScope['qty']), BigDecimal::of($sourceScope['value']));
            if ($destinationPool->id !== $sourcePool->id) {
                $destinationScope = $this->scope($item, $destination, $batch, $attributes);
                $this->pools->write($destinationPool, BigDecimal::of($destinationScope['qty']), BigDecimal::of($destinationScope['value']));
            }
            foreach ([[$sourcePool, 'SOURCE_OUT', $qty->negated(), $baseAmount->negated()], [$destinationPool, 'DESTINATION_IN', $qty, $baseAmount]] as [$pool, $effect, $entryQty, $amount]) {
                $move->valuationEntries()->create([
                    'avco_pool_id' => $pool->id, 'pool_effect' => $effect, 'qty' => (string) $entryQty,
                    'unit_cost' => (string) $baseUnitCost, 'amount' => (string) $amount, 'base_amount' => (string) $amount, 'account_id' => $accountId,
                ]);
            }
        } else {
            $this->recordFifoConsumption($move, $allocations);
            foreach ($allocations as $allocation) {
                $amount = $allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))->toScale(4, RoundingMode::HALF_UP);
                foreach ([[$allocation['qty']->negated(), $amount->negated()], [$allocation['qty'], $amount]] as [$entryQty, $entryAmount]) {
                    $move->valuationEntries()->create([
                        'avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) $entryQty,
                        'unit_cost' => $allocation['layer']->base_unit_cost, 'amount' => (string) $entryAmount,
                        'base_amount' => (string) $entryAmount, 'account_id' => $accountId,
                    ]);
                }
            }
        }

        StockTransferDetail::query()->create([
            'stock_move_id' => $move->id, 'source_avco_pool_id' => $sourcePool?->id,
            'destination_avco_pool_id' => $destinationPool?->id,
        ]);

        return $move->load(['valuationEntries', 'transferDetail']);
    }

    /** @return Collection<int, array{layer: StockLayer, qty: BigDecimal}> */
    private function allocateLive(Item $item, Location $location, ?StockBatch $batch, ?string $serialNo, BigDecimal $qty): Collection
    {
        $layers = $this->layers->lockIssueLayers($item, $location, $batch, $serialNo);
        $allocations = $this->layers->allocate($layers, $qty);
        $covered = $allocations->reduce(fn (BigDecimal $sum, array $a): BigDecimal => $sum->plus($a['qty']), BigDecimal::zero());
        if (! $covered->isEqualTo($qty)) {
            throw new DomainInvariantException('The live ledger no longer has physical cover for this backdated movement.', 'moved_at');
        }

        return $allocations;
    }

    /** @return Collection<int, array{layer: StockLayer, qty: BigDecimal}> */
    private function allocateHistorically(Item $item, Location $location, ?StockBatch $batch, ?string $serialNo, BigDecimal $qty, CarbonInterface $movedAt): Collection
    {
        $planned = $this->historical->allocate($item, $location, $batch, $serialNo, $qty, $movedAt);
        $layers = StockLayer::query()->whereIn('id', $planned->pluck('layer.id'))->lockForUpdate()->get()->keyBy('id');

        return $planned->map(function (array $allocation) use ($layers): array {
            $layer = $layers->get($allocation['layer']->id);
            if ($layer === null || BigDecimal::of($layer->qty_remaining)->isLessThan($allocation['qty'])) {
                throw new DomainInvariantException('The live ledger no longer has physical cover for this backdated movement.', 'moved_at');
            }

            return ['layer' => $layer, 'qty' => $allocation['qty']];
        });
    }

    /** @param Collection<int, array{layer: StockLayer, qty: BigDecimal}> $allocations */
    private function decrementLayers(Collection $allocations): void
    {
        foreach ($allocations as $allocation) {
            $allocation['layer']->update(['qty_remaining' => (string) BigDecimal::of($allocation['layer']->qty_remaining)->minus($allocation['qty'])]);
        }
    }

    /** @param Collection<int, array{layer: StockLayer, qty: BigDecimal}> $allocations */
    private function recordFifoConsumption(StockMove $move, Collection $allocations): void
    {
        foreach ($allocations as $allocation) {
            StockLayerConsumption::query()->create([
                'stock_move_id' => $move->id, 'stock_layer_id' => $allocation['layer']->id,
                'qty_consumed' => (string) $allocation['qty'], 'unit_cost' => $allocation['layer']->base_unit_cost,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{qty: string, value: string, average: string}
     */
    private function scope(Item $item, Location $location, ?StockBatch $batch, array $attributes): array
    {
        $key = match ($item->avco_pool_scope) {
            AvcoPoolScope::WarehouseItem => 'warehouse:'.$location->warehouse_id,
            AvcoPoolScope::CompanyItemBatch => 'batch:'.$batch?->id,
            default => 'company',
        };

        return $attributes['movement_impact']['counterfactual_scopes'][$key];
    }
}
