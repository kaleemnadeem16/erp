<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Movements;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\PostedMovement;
use App\Modules\Inventory\Support\StockLayerAllocation;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Outbound issue posting for PostInventoryMovement, including ADR 0037
 * authorised negative-stock consumption. Internal to the sole movement
 * boundary — never call this from outside that Action. Runs inside the
 * boundary's transaction.
 */
final class IssuePoster
{
    public function __construct(
        private readonly StockIdentity $identity,
        private readonly LayerAllocator $layers,
        private readonly AvcoPoolManager $pools,
    ) {}

    /** @param array{item_id:int, location_id:int, qty:string, uom_id:int, moved_at:mixed, source_type:string, source_id:int, batch_no?:string|null, serial_no?:string|null, override_request_id?:int|null, from_layer_id?:int|null} $input */
    public function post(array $input): PostedMovement
    {
        $existing = StockMove::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
        if ($existing !== null) {
            return new PostedMovement($existing->load(['valuationEntries', 'negativeStockShortage']), null);
        }

        // Archiving stops new orders, not stock already in the warehouse leaving it (rejected exits, scraps).
        $item = Item::withTrashed()->findOrFail($input['item_id']);
        // The location row is the stable lock even when this exact identity
        // has no layer yet. It closes the zero-stock issue/receipt race that
        // row-locking only existing layers cannot serialize.
        $location = Location::query()->with('warehouse')->lockForUpdate()->findOrFail($input['location_id']);
        $qty = BigDecimal::of($input['qty']);
        if ($qty->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('Stock issue quantity must be greater than zero.', 'qty');
        }
        if (! $item->is_stocked || $item->stock_uom_id !== $input['uom_id']) {
            throw new DomainInvariantException('Stock issues require a stocked item in its pinned stock UoM.', 'uom_id');
        }
        if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
            throw new DomainInvariantException('Stock can only be issued from an active stock-holding location.', 'location_id');
        }
        if (StockMove::query()->where('item_id', $item->id)->where('moved_at', '>', $input['moved_at'])->exists()) {
            throw new DomainInvariantException('This issue conflicts with later valuation history and requires the controlled backdating workflow.', 'moved_at');
        }

        $batchNo = $this->identity->normalisedBatchNo($input);
        $serialNo = $this->identity->normalisedSerialNo($input);
        $this->identity->validateIssueTracking($item, $qty, $batchNo, $serialNo);
        $batch = $this->identity->findOnHandBatch($item, $batchNo);

        $layers = $this->layers->lockIssueLayers($item, $location, $batch, $serialNo);
        // A concurrent retry may have passed the unlocked check before the
        // first request committed. The layer lock serialises the ordinary
        // same-stock path; re-check before allocating or mutating anything.
        $existing = StockMove::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
        if ($existing !== null) {
            return new PostedMovement($existing->load(['valuationEntries', 'negativeStockShortage']), null);
        }
        // A rejected-stock exit (ADR 0097) consumes exactly one named layer; every other issue allocates oldest-first over what is issuable.
        $allocations = isset($input['from_layer_id'])
            ? $this->layers->allocateFromLayer($layers, (int) $input['from_layer_id'], $qty)
            : $this->layers->allocate($layers, $qty);
        $availableQty = $allocations->reduce(
            fn (BigDecimal $total, array $allocation): BigDecimal => $total->plus($allocation['qty']),
            BigDecimal::zero(),
        );
        $shortageQty = $qty->minus($availableQty);
        $override = $shortageQty->isGreaterThan(0)
            ? $this->authoriseShortage($input, $item, $location, $batch, $qty, $shortageQty)
            : null;
        if ($shortageQty->isZero() && isset($input['override_request_id'])) {
            $this->validateUnusedOverride($input, $item, $location, $batch, $qty);
        }
        $baseCurrencyId = Company::query()->findOrFail(app(CompanyContext::class)->id())->base_currency_id;
        if ($baseCurrencyId === null) {
            throw new DomainInvariantException('A company base currency is required before stock can be issued.', 'item_id');
        }

        $pool = $item->valuation_method === ValuationMethod::Avco ? $this->pools->lock($item, $location, $batch) : null;
        $provisionalUnitCost = $shortageQty->isGreaterThan(0)
            ? $this->provisionalUnitCost($item, $location, $batch, $pool)
            : null;
        $baseAmount = $pool !== null
            ? $this->avcoBaseAmount($pool, $qty, $override !== null ? $provisionalUnitCost : null)
            : $allocations->reduce(
                fn (BigDecimal $total, array $allocation): BigDecimal => $total->plus($allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))),
                BigDecimal::zero(),
            )->plus($shortageQty->multipliedBy($provisionalUnitCost ?? BigDecimal::zero()))->toScale(4, RoundingMode::HALF_UP);
        $unitCost = $baseAmount->dividedBy($qty, 4, RoundingMode::HALF_UP);

        $move = StockMove::query()->create([
            'movement_kind' => 'ISSUE',
            'item_id' => $item->id, 'from_location_id' => $location->id, 'to_location_id' => null,
            'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $input['moved_at'],
            'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
            'unit_cost' => (string) $unitCost, 'currency_id' => $baseCurrencyId, 'fx_rate' => '1.000000000',
            'batch_id' => $batch?->id, 'serial_no' => $serialNo,
        ]);

        foreach ($allocations as $allocation) {
            $layer = $allocation['layer'];
            $layer->update(['qty_remaining' => (string) BigDecimal::of($layer->qty_remaining)->minus($allocation['qty'])]);
        }
        if ($pool !== null) {
            $this->drawDownPool($pool, $qty, $baseAmount);
        } else {
            $this->layers->recordConsumption($move, $allocations);
        }

        $accountId = $item->resolveInventoryAccountId();
        if ($accountId === null) {
            throw new DomainInvariantException('A stock issue requires a resolved inventory account.', 'item_id');
        }
        if ($pool !== null) {
            $move->valuationEntries()->create([
                'avco_pool_id' => $pool->id, 'pool_effect' => 'SOURCE_OUT', 'qty' => (string) $qty->negated(),
                'unit_cost' => (string) $unitCost, 'amount' => (string) $baseAmount->negated(),
                'base_amount' => (string) $baseAmount->negated(), 'account_id' => $accountId,
            ]);
        } else {
            foreach ($allocations as $allocation) {
                $allocationAmount = $allocation['qty']->multipliedBy(BigDecimal::of($allocation['layer']->base_unit_cost))->toScale(4, RoundingMode::HALF_UP);
                $move->valuationEntries()->create([
                    'avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) $allocation['qty']->negated(),
                    'unit_cost' => $allocation['layer']->base_unit_cost, 'amount' => (string) $allocationAmount->negated(),
                    'base_amount' => (string) $allocationAmount->negated(), 'account_id' => $accountId,
                ]);
            }
            if ($shortageQty->isGreaterThan(0)) {
                $shortageAmount = $shortageQty->multipliedBy($provisionalUnitCost)->toScale(4, RoundingMode::HALF_UP);
                $move->valuationEntries()->create([
                    'avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) $shortageQty->negated(),
                    'unit_cost' => (string) $provisionalUnitCost, 'amount' => (string) $shortageAmount->negated(),
                    'base_amount' => (string) $shortageAmount->negated(), 'account_id' => $accountId,
                ]);
            }
        }
        if ($override !== null) {
            NegativeStockShortage::query()->create([
                'item_id' => $item->id, 'location_id' => $location->id, 'batch_id' => $batch?->id,
                'avco_pool_id' => $pool?->id, 'qty_open' => (string) $shortageQty,
                'provisional_unit_cost' => (string) $provisionalUnitCost, 'status' => 'OPEN',
                'source_move_id' => $move->id, 'override_request_id' => $override->id,
            ]);
        }

        return new PostedMovement(
            $move->load(['valuationEntries', 'negativeStockShortage']),
            $allocations->map(fn (array $allocation): StockLayerAllocation => new StockLayerAllocation($allocation['layer']->id, (string) $allocation['qty']->toScale(4, RoundingMode::HALF_UP)))->values()->all(),
            (string) $shortageQty->toScale(4, RoundingMode::HALF_UP),
        );
    }

    /**
     * An AVCO issue's base value. An exact full drawdown consumes the pool's
     * precise remaining value so rounding can never strand a residual. An
     * authorised shortage prices the whole quantity at its provisional cost.
     * A pool that cannot support the quantity — dragged non-positive or below
     * this location's physical stock by an authorised shortage elsewhere in
     * the same pool — never blocks physically available stock (ADR 0037:
     * availability is exact-location); the value uses the pool's last valid
     * official average, the same provisional treatment transfers apply, and
     * the pool freezes at that rate until inbound recovery.
     */
    private function avcoBaseAmount(AvcoPool $pool, BigDecimal $qty, ?BigDecimal $overrideCost): BigDecimal
    {
        $poolQty = BigDecimal::of($pool->qty_on_hand);
        if ($overrideCost === null && $poolQty->isEqualTo($qty)) {
            return BigDecimal::of($pool->inventory_value);
        }
        $rate = $overrideCost ?? ($poolQty->isGreaterThanOrEqualTo($qty)
            ? BigDecimal::of($pool->base_avg_unit_cost)
            : $this->defensibleAvcoCost($pool, 'qty', 'No defensible AVCO cost history exists for this pool-unsupported issue.'));

        return $qty->multipliedBy($rate)->toScale(4, RoundingMode::HALF_UP);
    }

    private function drawDownPool(AvcoPool $pool, BigDecimal $qty, BigDecimal $baseAmount): void
    {
        $newQty = BigDecimal::of($pool->qty_on_hand)->minus($qty);
        $newValue = BigDecimal::of($pool->inventory_value)->minus($baseAmount)->toScale(4, RoundingMode::HALF_UP);
        if ($newQty->isGreaterThan(0)) {
            $average = $newValue->dividedBy($newQty, 4, RoundingMode::HALF_UP);
            $pool->update([
                'qty_on_hand' => (string) $newQty, 'inventory_value' => (string) $newValue,
                'avg_unit_cost' => (string) $average, 'base_avg_unit_cost' => (string) $average,
                'last_valid_avg_unit_cost' => (string) $average,
            ]);

            return;
        }
        if ($newQty->isZero() && $newValue->isZero()) {
            // A clean full drawdown to zero is an ordinary empty pool, not an
            // ADR 0037 excursion — never freeze, never lose last_valid.
            $pool->update([
                'qty_on_hand' => '0.0000', 'inventory_value' => '0.0000',
                'avg_unit_cost' => '0.0000', 'base_avg_unit_cost' => '0.0000',
            ]);

            return;
        }
        $pool->update([
            'qty_on_hand' => (string) $newQty, 'inventory_value' => (string) $newValue,
            'avg_unit_cost' => $pool->last_valid_avg_unit_cost, 'base_avg_unit_cost' => $pool->last_valid_avg_unit_cost,
            'status' => 'FROZEN',
        ]);
    }

    /** @param array{source_type:string, source_id:int, override_request_id?:int|null} $input */
    private function authoriseShortage(array $input, Item $item, Location $location, ?StockBatch $batch, BigDecimal $qty, BigDecimal $shortageQty): NegativeStockOverrideRequest
    {
        if (! isset($input['override_request_id'])) {
            throw new DomainInvariantException('Insufficient available stock requires an authorised negative-stock override.', 'qty');
        }

        $request = NegativeStockOverrideRequest::query()->with('approval')->lockForUpdate()->findOrFail($input['override_request_id']);
        $this->validateOverrideIdentity($request, $input, $item, $location, $batch, $qty);
        if ($shortageQty->isGreaterThan(BigDecimal::of($request->shortage_qty))) {
            throw new DomainInvariantException('The current shortage is worse than the authorised shortage.', 'qty');
        }
        if ($request->authorisation_mode === NegativeStockAuthorisationMode::PriorApproval
            && $request->approval?->decision !== NegativeStockApprovalDecision::Approved) {
            throw new DomainInvariantException('This negative-stock request has not been approved.', 'override_request_id');
        }
        if ($request->shortage()->lockForUpdate()->exists()) {
            throw new DomainInvariantException('This negative-stock override has already been consumed.', 'override_request_id');
        }
        if ($this->hasOpenShortage($item, $location, $batch)) {
            throw new DomainInvariantException('An existing shortage at this exact stock identity must be settled before it can deepen.', 'qty');
        }

        return $request;
    }

    /** @param array{source_type:string, source_id:int, override_request_id?:int|null} $input */
    private function validateUnusedOverride(array $input, Item $item, Location $location, ?StockBatch $batch, BigDecimal $qty): void
    {
        $request = NegativeStockOverrideRequest::query()->lockForUpdate()->findOrFail($input['override_request_id']);
        $this->validateOverrideIdentity($request, $input, $item, $location, $batch, $qty);
    }

    /** @param array{source_type:string, source_id:int} $input */
    private function validateOverrideIdentity(NegativeStockOverrideRequest $request, array $input, Item $item, Location $location, ?StockBatch $batch, BigDecimal $qty): void
    {
        if ($request->item_id !== $item->id
            || $request->location_id !== $location->id
            || $request->batch_id !== $batch?->id
            || $request->source_type !== $input['source_type']
            || $request->source_id !== $input['source_id']
            || ! BigDecimal::of($request->requested_qty)->isEqualTo($qty)) {
            throw new DomainInvariantException('The override does not match this exact stock issue.', 'override_request_id');
        }
    }

    private function provisionalUnitCost(Item $item, Location $location, ?StockBatch $batch, ?AvcoPool $pool): BigDecimal
    {
        if ($item->valuation_method === ValuationMethod::Avco) {
            if ($pool === null) {
                throw new DomainInvariantException('The resolved AVCO pool is missing.', 'item_id');
            }

            return $this->defensibleAvcoCost($pool, 'override_request_id', 'No defensible AVCO cost history exists for this shortage.');
        }

        $layer = StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)
            ->when($batch === null, fn ($query) => $query->whereNull('batch_id'), fn ($query) => $query->where('batch_id', $batch->id))
            ->whereNull('serial_no')->orderByDesc('received_at')->orderByDesc('id')->lockForUpdate()->first();
        if ($layer === null || BigDecimal::of($layer->base_unit_cost)->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('No defensible FIFO acquisition cost exists for this shortage.', 'override_request_id');
        }

        return BigDecimal::of($layer->base_unit_cost);
    }

    private function defensibleAvcoCost(AvcoPool $pool, string $field, string $message): BigDecimal
    {
        $cost = BigDecimal::of($pool->last_valid_avg_unit_cost);
        if ($cost->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException($message, $field);
        }

        return $cost;
    }

    private function hasOpenShortage(Item $item, Location $location, ?StockBatch $batch): bool
    {
        return NegativeStockShortage::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)
            ->when($batch === null, fn ($builder) => $builder->whereNull('batch_id'), fn ($builder) => $builder->where('batch_id', $batch->id))
            ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED', 'OPEN_CUTOVER_SHORTAGE'])
            ->lockForUpdate()->exists();
    }
}
