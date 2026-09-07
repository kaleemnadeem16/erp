<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Actions\Movements\AvcoPoolManager;
use App\Modules\Inventory\Actions\Movements\LayerAllocator;
use App\Modules\Inventory\Models\AvcoPool;
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
 * Posts an approved backdated ISSUE or TRANSFER for real, mirroring
 * IssuePoster/TransferPoster's posting shape at the request's true
 * historical moved_at instead of today — whether or not the replay changes
 * any other real move. Internal to ExecuteRecalculation; must run inside
 * that Action's locked transaction. Shortage/override handling is
 * deliberately absent: MovementReplayScope's guard already refuses any
 * history this workflow can reach that involves one.
 *
 * FIFO/SPECIFIC use LayerCounterfactualReplayer::planOutboundInsertion() —
 * which layer(s) a draw lands on determines its cost, and only layers that
 * had actually arrived by the backdated moved_at may be drawn from — then
 * FifoReceiptReallocator::applyCorrections() for whatever later real moves
 * that draw displaces, cascading through transfers exactly like the
 * receipt-execution path (empty for the clean-replay case). Corrections run
 * *before* this move's own allocation is locked: see
 * allocateHistorically()'s docblock for why the order matters. The new
 * move's own valuation entry plus a residual (when nonzero) reconcile to the
 * approved, already-revalidated valuation_adjustment — the same "own entry +
 * residual" shape ExecuteRecalculation already uses for receipts.
 *
 * AVCO uses the same live, today's-remaining-layers allocation
 * TransferPoster always uses regardless of valuation method, since an AVCO
 * layer is pure traceability — cost comes from the pool, not the layer, and
 * there is no consumption ledger to reconstruct historical availability from
 * anyway (recordConsumption() is never called for AVCO). Every pool the
 * connected component actually touches converges to its own
 * counterfactual_scopes snapshot (not just the request's own
 * source/destination — a changed later move elsewhere can shift a third
 * pool), each with its own residual so the GL feed reconciles per account.
 */
final class OutboundRecalculationExecutor
{
    public function __construct(
        private readonly LayerCounterfactualReplayer $layerReplay,
        private readonly FifoReceiptReallocator $fifo,
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

        if ($item->valuation_method === ValuationMethod::Avco) {
            $allocations = $this->allocateLive($item, $location, $batch, $request->serial_no, $qty);
            $scopeKeys = collect(array_keys($attributes['movement_impact']['counterfactual_scopes']))->map(fn ($key): string => (string) $key);
            $pools = $this->pools->lockByScopeKeys($item, $scopeKeys);
        } else {
            // Corrections for any displaced later move already ran inside
            // allocateHistorically(), before this move's own allocation was
            // locked — see that method's docblock for why the order matters.
            [$allocations] = $this->allocateHistorically($request, $item, $location, $batch, $request->serial_no, $qty, $request->moved_at);
            $pools = null;
        }

        return $request->movement_kind === RecalculationMovementKind::Transfer
            ? $this->postTransfer($request, $item, $company, $location, $destination, $batch, $qty, $baseUnitCost, $baseAmount, $accountId, $allocations, $attributes, $pools)
            : $this->postIssue($request, $item, $company, $location, $batch, $qty, $baseUnitCost, $baseAmount, $accountId, $allocations, $attributes, $pools);
    }

    /**
     * @param  Collection<int, array{layer: StockLayer, qty: BigDecimal}>  $allocations
     * @param  array<string, mixed>  $attributes
     * @param  ?Collection<string, AvcoPool>  $pools
     */
    private function postIssue(
        RecalculationRequest $request, Item $item, Company $company, Location $location, ?StockBatch $batch,
        BigDecimal $qty, BigDecimal $baseUnitCost, BigDecimal $baseAmount, int $accountId, Collection $allocations, array $attributes,
        ?Collection $pools,
    ): StockMove {
        $sourceKey = $this->scopeKey($item, $location, $batch);
        $pool = $pools?->get($sourceKey);

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
            $this->convergeAvcoPools($pools, $attributes);
            $move->valuationEntries()->create([
                'avco_pool_id' => $pool->id, 'pool_effect' => 'SOURCE_OUT', 'qty' => (string) $qty->negated(),
                'unit_cost' => (string) $baseUnitCost, 'amount' => (string) $baseAmount->negated(),
                'base_amount' => (string) $baseAmount->negated(), 'account_id' => $accountId,
            ]);
            $this->postAvcoResidual($pools, $attributes, $move, $accountId, [[$sourceKey, $baseAmount->negated()]]);
        } else {
            $this->recordFifoConsumption($move, $allocations);
            $ownValue = $this->postOwnLayerValuation($move, $allocations, $accountId, negate: true);
            $this->postFifoResidual($move, $ownValue->negated(), $attributes, $accountId);
        }

        return $move->load('valuationEntries');
    }

    /**
     * @param  Collection<int, array{layer: StockLayer, qty: BigDecimal}>  $allocations
     * @param  array<string, mixed>  $attributes
     * @param  ?Collection<string, AvcoPool>  $pools
     */
    private function postTransfer(
        RecalculationRequest $request, Item $item, Company $company, Location $location, ?Location $destination, ?StockBatch $batch,
        BigDecimal $qty, BigDecimal $baseUnitCost, BigDecimal $baseAmount, int $accountId, Collection $allocations, array $attributes,
        ?Collection $pools,
    ): StockMove {
        $sourceKey = $this->scopeKey($item, $location, $batch);
        $destinationKey = $this->scopeKey($item, $destination, $batch);
        $sourcePool = $pools?->get($sourceKey);
        $destinationPool = $pools?->get($destinationKey);

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
            $this->convergeAvcoPools($pools, $attributes);
            foreach ([[$sourcePool, 'SOURCE_OUT', $qty->negated(), $baseAmount->negated()], [$destinationPool, 'DESTINATION_IN', $qty, $baseAmount]] as [$pool, $effect, $entryQty, $amount]) {
                $move->valuationEntries()->create([
                    'avco_pool_id' => $pool->id, 'pool_effect' => $effect, 'qty' => (string) $entryQty,
                    'unit_cost' => (string) $baseUnitCost, 'amount' => (string) $amount, 'base_amount' => (string) $amount, 'account_id' => $accountId,
                ]);
            }
            $this->postAvcoResidual($pools, $attributes, $move, $accountId, [
                [$sourceKey, $baseAmount->negated()],
                [$destinationKey, $baseAmount],
            ]);
        } else {
            $this->recordFifoConsumption($move, $allocations);
            $ownValue = BigDecimal::zero();
            foreach ($allocations as $allocation) {
                $amount = $allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))->toScale(4, RoundingMode::HALF_UP);
                $ownValue = $ownValue->plus($amount);
                foreach ([[$allocation['qty']->negated(), $amount->negated()], [$allocation['qty'], $amount]] as [$entryQty, $entryAmount]) {
                    $move->valuationEntries()->create([
                        'avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) $entryQty,
                        'unit_cost' => $allocation['layer']->base_unit_cost, 'amount' => (string) $entryAmount,
                        'base_amount' => (string) $entryAmount, 'account_id' => $accountId,
                    ]);
                }
            }
            // A transfer's own entries net to zero (value leaves and arrives
            // in the same move); the residual is measured against the
            // approved adjustment directly, same as the issue path.
            $this->postFifoResidual($move, BigDecimal::zero(), $attributes, $accountId);
        }

        StockTransferDetail::query()->create([
            'stock_move_id' => $move->id, 'source_avco_pool_id' => $sourcePool?->id,
            'destination_avco_pool_id' => $destinationPool?->id,
        ]);

        return $move->load(['valuationEntries', 'transferDetail']);
    }

    /**
     * Plugs the exact remainder of the approved valuation_adjustment this
     * move's own entries didn't already cover — identical in spirit to the
     * zero-qty residual ExecuteRecalculation posts for a backdated receipt.
     * Zero for the clean-replay case, where the move's own value already is
     * the entire adjustment.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function postFifoResidual(StockMove $move, BigDecimal $ownAmount, array $attributes, int $accountId): void
    {
        $residual = BigDecimal::of($attributes['valuation_adjustment'])->minus($ownAmount)->toScale(4, RoundingMode::HALF_UP);
        if (! $residual->isZero()) {
            $move->valuationEntries()->create([
                'avco_pool_id' => null, 'pool_effect' => null, 'qty' => '0.0000', 'unit_cost' => '0.0000',
                'amount' => (string) $residual, 'base_amount' => (string) $residual, 'account_id' => $accountId,
            ]);
        }
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

    /**
     * Corrections for later real moves must run before this move's own
     * allocation is locked and checked: a later move's stale
     * over-consumption of a layer is only released back by
     * FifoReceiptReallocator::applyCorrections(), and this move's own draw
     * may depend on exactly that freed capacity (planOutboundInsertion()
     * simulated it as available at the earlier backdated date, before the
     * later move had actually happened at all). Corrections never depend on
     * this move's own posting in the other direction — they touch only
     * already-existing real moves — so this ordering always has a solution
     * whenever the simulation found one.
     *
     * @return array{0: Collection<int, array{layer: StockLayer, qty: BigDecimal}>, 1: LayerReplayPlan}
     */
    private function allocateHistorically(RecalculationRequest $request, Item $item, Location $location, ?StockBatch $batch, ?string $serialNo, BigDecimal $qty, CarbonInterface $movedAt): array
    {
        ['ownAllocation' => $planned, 'plan' => $correctionPlan] = $this->layerReplay->planOutboundInsertion($item, $location, $batch, $serialNo, $qty, $movedAt);
        if ($correctionPlan->allocationsByMove->isNotEmpty()) {
            $this->fifo->applyCorrections($request, $correctionPlan);
        }
        $layers = StockLayer::query()->whereIn('id', $planned->pluck('layer.id'))->lockForUpdate()->get()->keyBy('id');

        $allocations = $planned->map(function (array $allocation) use ($layers): array {
            $layer = $layers->get($allocation['layer']->id);
            if ($layer === null || BigDecimal::of($layer->qty_remaining)->isLessThan($allocation['qty'])) {
                throw new DomainInvariantException('The live ledger no longer has physical cover for this backdated movement.', 'moved_at');
            }

            return ['layer' => $layer, 'qty' => $allocation['qty']];
        });

        return [$allocations, $correctionPlan];
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
     * Posts one negative valuation entry per allocated layer (an issue's
     * outbound draw) and returns their unsigned total, for the caller to
     * measure the residual against.
     *
     * @param  Collection<int, array{layer: StockLayer, qty: BigDecimal}>  $allocations
     */
    private function postOwnLayerValuation(StockMove $move, Collection $allocations, int $accountId, bool $negate): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($allocations as $allocation) {
            $amount = $allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))->toScale(4, RoundingMode::HALF_UP);
            $total = $total->plus($amount);
            $signedAmount = $negate ? $amount->negated() : $amount;
            $move->valuationEntries()->create([
                'avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) ($negate ? $allocation['qty']->negated() : $allocation['qty']),
                'unit_cost' => $allocation['layer']->base_unit_cost, 'amount' => (string) $signedAmount,
                'base_amount' => (string) $signedAmount, 'account_id' => $accountId,
            ]);
        }

        return $total;
    }

    private function scopeKey(Item $item, Location $location, ?StockBatch $batch): string
    {
        return match ($item->avco_pool_scope) {
            AvcoPoolScope::WarehouseItem => 'warehouse:'.$location->warehouse_id,
            AvcoPoolScope::CompanyItemBatch => 'batch:'.$batch?->id,
            default => 'company',
        };
    }

    /**
     * Converges every pool the connected component actually touched to its
     * own counterfactual snapshot — not just the request's own source
     * (+destination), since a changed later move elsewhere in the component
     * can shift a third pool the new move never directly posts to.
     *
     * @param  Collection<string, AvcoPool>  $pools
     * @param  array<string, mixed>  $attributes
     */
    private function convergeAvcoPools(Collection $pools, array $attributes): void
    {
        foreach ($pools as $key => $pool) {
            $snapshot = $attributes['movement_impact']['counterfactual_scopes'][$key];
            $this->pools->write($pool, BigDecimal::of($snapshot['qty']), BigDecimal::of($snapshot['value']));
        }
    }

    /**
     * Plugs whatever each pool's own scope delta doesn't already have an
     * entry for — identical in spirit to the zero-qty residual
     * ExecuteRecalculation posts for a backdated receipt, just measured per
     * pool instead of once, since a connected component can touch several.
     *
     * @param  Collection<string, AvcoPool>  $pools
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: string, 1: BigDecimal}>  $ownContributions  scope key => amount this move's own entries already posted to it
     */
    private function postAvcoResidual(Collection $pools, array $attributes, StockMove $move, int $accountId, array $ownContributions): void
    {
        $ownByKey = collect($ownContributions)->groupBy(fn (array $pair): string => $pair[0])
            ->map(fn (Collection $pairs): BigDecimal => $pairs->reduce(fn (BigDecimal $sum, array $pair): BigDecimal => $sum->plus($pair[1]), BigDecimal::zero()));

        foreach ($pools as $key => $pool) {
            $delta = BigDecimal::of($attributes['movement_impact']['counterfactual_scopes'][$key]['value'])
                ->minus($attributes['movement_impact']['original_scopes'][$key]['value']);
            $residual = $delta->minus($ownByKey->get($key, BigDecimal::zero()))->toScale(4, RoundingMode::HALF_UP);
            if (! $residual->isZero()) {
                $move->valuationEntries()->create([
                    'avco_pool_id' => $pool->id, 'pool_effect' => 'VALUATION_ADJUSTMENT', 'qty' => '0.0000', 'unit_cost' => '0.0000',
                    'amount' => (string) $residual, 'base_amount' => (string) $residual, 'account_id' => $accountId,
                ]);
            }
        }
    }
}
