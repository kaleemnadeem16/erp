<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Cutover;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Actions\Movements\AvcoPoolManager;
use App\Modules\Inventory\Actions\Movements\StockIdentity;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\InventoryCutoverAvcoPool;
use App\Modules\Inventory\Models\InventoryCutoverPolicyTransition;
use App\Modules\Inventory\Models\InventorySourceFact;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Posts one item's validated OPENING_STATE source facts as real stock,
 * internal to CommitInventoryCutoverOpeningState — never call this from
 * outside that Action. Reuses PostInventoryMovement's own StockIdentity/
 * AvcoPoolManager primitives (already shared with the Recalculation
 * boundary) rather than re-deriving batch/serial or pool-write rules.
 *
 * Every method posts a StockMove + StockLayer per real piece of evidence,
 * exactly like an ordinary receipt (ReceiptPoster), just sourced from a
 * cutover fact instead of a purchase — see postFactAsLayer(). The three
 * valuation methods differ only in what happens around that shared core:
 * - AVCO additionally converges the resolved target pool and records one
 *   InventoryCutoverAvcoPool provenance row per (policy transition, target
 *   pool) group — the one place a cutover-derived number (the pool average)
 *   isn't already evidenced by a single row elsewhere.
 * - FIFO posts a real, truly-dated layer for any fact carrying its own
 *   source_effective_at or serial identity; facts with neither are summarised
 *   data and are merged into one synthetic layer dated at cutover_at per
 *   docs/domain/inventory-cutover.md "Valuation continuity".
 * - SPECIFIC always posts one real layer per fact (its serial is real
 *   evidence itself; StockIdentity::validateReceiptTracking enforces the
 *   qty=1 + serial pairing).
 *
 * A negative fact quantity is refused: ADR 0037's cutover-shortage exception
 * workflow (resolution plan, target date, owner, evidence) does not exist
 * yet, so a genuine negative predecessor closing balance cannot be preserved
 * today. A zero-quantity fact is a no-op.
 */
final class OpeningStateItemPoster
{
    public function __construct(
        private readonly StockIdentity $identity,
        private readonly AvcoPoolManager $pools,
    ) {}

    /**
     * @param  Collection<int, InventorySourceFact>  $facts
     * @param  Collection<int, InventoryCutoverPolicyTransition>  $transitions
     */
    public function post(InventoryCutover $cutover, Item $item, Collection $facts, Collection $transitions, Company $company): void
    {
        $transition = $transitions->first();
        $targetMethod = $transition->target_valuation_method;
        $targetScope = $transition->target_avco_scope;
        if ($item->valuation_method !== $targetMethod || $item->avco_pool_scope !== $targetScope) {
            $item->update([
                'valuation_method' => $targetMethod,
                'avco_pool_scope' => $targetMethod === ValuationMethod::Avco ? $targetScope : null,
            ]);
        }

        $usable = [];
        foreach ($facts as $fact) {
            $qty = BigDecimal::of($fact->qty);
            if ($qty->isNegative()) {
                throw new DomainInvariantException(
                    "Item {$item->code} has a negative opening quantity at cutover; the negative-opening cutover exception workflow is not implemented yet.",
                    'qty',
                );
            }
            if ($qty->isZero()) {
                continue;
            }
            if ($fact->uom_id !== $item->stock_uom_id) {
                throw new DomainInvariantException("Item {$item->code}'s opening quantity must be supplied in its stock UoM.", 'uom_id');
            }
            $usable[] = $fact;
        }
        $usable = collect($usable);
        if ($usable->isEmpty()) {
            return;
        }

        match ($targetMethod) {
            ValuationMethod::Avco => $this->postAvco($cutover, $item, $usable, $transitions, $company, $targetScope),
            ValuationMethod::Fifo => $this->postFifo($item, $usable, $cutover->cutover_at, $company),
            ValuationMethod::Specific => $this->postSpecific($item, $usable, $cutover->cutover_at, $company),
        };
    }

    /** @param Collection<int, InventorySourceFact> $facts */
    private function postSpecific(Item $item, Collection $facts, CarbonInterface $cutoverAt, Company $company): void
    {
        foreach ($facts as $fact) {
            [$batch, $serialNo] = $this->resolveIdentity($item, $fact);
            $location = $this->location($fact->location_id);
            $this->postFactAsLayer($item, $cutoverAt, $fact, $company, $batch, $serialNo, $location, null);
        }
    }

    /** @param Collection<int, InventorySourceFact> $facts */
    private function postFifo(Item $item, Collection $facts, CarbonInterface $cutoverAt, Company $company): void
    {
        $individual = $facts->filter(fn (InventorySourceFact $f): bool => $f->serial_no !== null || $f->source_effective_at !== null);
        foreach ($individual as $fact) {
            [$batch, $serialNo] = $this->resolveIdentity($item, $fact);
            $location = $this->location($fact->location_id);
            $this->postFactAsLayer($item, $cutoverAt, $fact, $company, $batch, $serialNo, $location, null);
        }

        $summarised = $facts->reject(fn (InventorySourceFact $f): bool => $f->serial_no !== null || $f->source_effective_at !== null);
        $groups = $summarised->groupBy(fn (InventorySourceFact $f): string => $f->location_id.'|'.($f->batch_no ?? ''));
        foreach ($groups as $group) {
            $this->postSyntheticLayer($item, $cutoverAt, $group, $company);
        }
    }

    /**
     * @param  Collection<int, InventorySourceFact>  $facts
     * @param  Collection<int, InventoryCutoverPolicyTransition>  $transitions
     */
    private function postAvco(InventoryCutover $cutover, Item $item, Collection $facts, Collection $transitions, Company $company, AvcoPoolScope $targetScope): void
    {
        $transitionsByIdentity = $transitions->keyBy(fn (InventoryCutoverPolicyTransition $t): string => $this->identityKey(
            $t->source_valuation_method->value, $t->source_avco_scope?->value, $t->source_pool_identity,
        ));

        /** @var array<string, array{transition_id:int, pool_id:int, warehouse_id:?int, batch_id:?int, qty:BigDecimal, base_value:BigDecimal}> $contributions */
        $contributions = [];
        foreach ($facts as $fact) {
            [$batch, $serialNo] = $this->resolveIdentity($item, $fact);
            $location = $this->location($fact->location_id);
            $pool = $this->pools->lock($item, $location, $batch);
            $this->postFactAsLayer($item, $cutover->cutover_at, $fact, $company, $batch, $serialNo, $location, $pool);

            $transition = $transitionsByIdentity->get($this->identityKey(
                $fact->source_valuation_method->value, $fact->source_avco_scope?->value, $fact->source_valuation_pool_identity,
            ));
            if ($transition === null) {
                throw new DomainInvariantException("No recorded policy transition covers item {$item->code}'s opening source identity.", 'source_valuation_pool_identity');
            }
            $warehouseId = $targetScope === AvcoPoolScope::WarehouseItem ? $location->warehouse_id : null;
            $batchId = $targetScope === AvcoPoolScope::CompanyItemBatch ? $batch?->id : null;
            $groupKey = $transition->id.'|'.($warehouseId ?? 'null').'|'.($batchId ?? 'null');
            $contributions[$groupKey] ??= [
                'transition_id' => $transition->id, 'pool_id' => $pool->id,
                'warehouse_id' => $warehouseId, 'batch_id' => $batchId,
                'qty' => BigDecimal::zero(), 'base_value' => BigDecimal::zero(),
            ];
            $contributions[$groupKey]['qty'] = $contributions[$groupKey]['qty']->plus($fact->qty);
            $contributions[$groupKey]['base_value'] = $contributions[$groupKey]['base_value']->plus($fact->base_amount);
        }

        foreach ($contributions as $contribution) {
            $exists = InventoryCutoverAvcoPool::query()
                ->where('policy_transition_id', $contribution['transition_id'])
                ->where('warehouse_id', $contribution['warehouse_id'])
                ->where('batch_id', $contribution['batch_id'])
                ->exists();
            if ($exists) {
                continue;
            }
            $qty = $contribution['qty'];
            $baseValue = $contribution['base_value']->toScale(4, RoundingMode::HALF_UP);
            InventoryCutoverAvcoPool::query()->create([
                'inventory_cutover_id' => $cutover->id, 'item_id' => $item->id,
                'policy_transition_id' => $contribution['transition_id'],
                'warehouse_id' => $contribution['warehouse_id'], 'batch_id' => $contribution['batch_id'],
                'target_avco_pool_id' => $contribution['pool_id'],
                'opening_quantity' => (string) $qty, 'opening_value' => (string) $baseValue,
                'base_opening_value' => (string) $baseValue,
                'derived_average' => (string) ($qty->isZero() ? BigDecimal::zero() : $baseValue->dividedBy($qty, 4, RoundingMode::HALF_UP)),
            ]);
        }
    }

    /**
     * Posts one fact as one real receipt-shaped move + layer, exactly like
     * ReceiptPoster's inbound shape — one valuation entry whose pool_effect
     * is set only when $pool is given, and (only then) the pool converges by
     * this fact's own quantity/value. The fact's own amount/base_amount are
     * trusted exactly as approved; never recomputed from a derived average.
     */
    private function postFactAsLayer(
        Item $item, CarbonInterface $cutoverAt, InventorySourceFact $fact, Company $company,
        ?StockBatch $batch, ?string $serialNo, Location $location, ?AvcoPool $pool,
    ): void {
        $qty = BigDecimal::of($fact->qty);
        $unitCost = $fact->unit_cost !== null
            ? BigDecimal::of($fact->unit_cost)
            : BigDecimal::of($fact->amount)->dividedBy($qty, 4, RoundingMode::HALF_UP);
        $baseUnitCost = BigDecimal::of($fact->base_amount)->dividedBy($qty, 4, RoundingMode::HALF_UP);
        [$currencyId, $fxRate] = $this->currencyFor($fact->currency_id, $fact->fx_rate, $company);
        $receivedAt = $fact->source_effective_at ?? $cutoverAt;

        $move = StockMove::query()->create([
            'movement_kind' => 'RECEIPT', 'item_id' => $item->id, 'from_location_id' => null, 'to_location_id' => $location->id,
            'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $receivedAt,
            'source_type' => 'INVENTORY_CUTOVER_OPENING', 'source_id' => $fact->id,
            'unit_cost' => (string) $unitCost, 'currency_id' => $currencyId, 'fx_rate' => $fxRate,
            'batch_id' => $batch?->id, 'serial_no' => $serialNo,
        ]);
        StockLayer::query()->create([
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty_received' => (string) $qty, 'qty_remaining' => (string) $qty,
            'unit_cost' => (string) $unitCost, 'currency_id' => $currencyId, 'fx_rate' => $fxRate,
            'base_unit_cost' => (string) $baseUnitCost, 'received_at' => $receivedAt,
            'source_type' => 'INVENTORY_CUTOVER_OPENING', 'source_id' => $fact->id,
            'batch_id' => $batch?->id, 'serial_no' => $serialNo,
        ]);
        $accountId = $item->resolveInventoryAccountId();
        if ($accountId === null) {
            throw new DomainInvariantException('A stock receipt requires a resolved inventory account.', 'item_id');
        }
        $move->valuationEntries()->create([
            'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'DESTINATION_IN',
            'qty' => (string) $qty, 'unit_cost' => (string) $unitCost,
            'amount' => (string) $fact->amount, 'base_amount' => (string) $fact->base_amount,
            'account_id' => $accountId,
        ]);
        if ($pool !== null) {
            $newQty = BigDecimal::of($pool->qty_on_hand)->plus($qty);
            $newValue = BigDecimal::of($pool->inventory_value)->plus($fact->base_amount);
            $this->pools->write($pool, $newQty, $newValue);
        }
    }

    /**
     * Merges facts with no source_effective_at and no serial into one
     * synthetic layer dated at cutover_at — FIFO's "one explicitly labelled
     * synthetic cutover layer" for summarised data with no reliable age.
     * Anchored at the group's lowest fact id: real evidence, not invented.
     *
     * @param  Collection<int, InventorySourceFact>  $facts
     */
    private function postSyntheticLayer(Item $item, CarbonInterface $cutoverAt, Collection $facts, Company $company): void
    {
        $first = $facts->first();
        [$batch] = $this->resolveIdentity($item, $first);
        $location = $this->location($first->location_id);
        $qty = $facts->reduce(fn (BigDecimal $sum, InventorySourceFact $f): BigDecimal => $sum->plus($f->qty), BigDecimal::zero());
        $amount = $facts->reduce(fn (BigDecimal $sum, InventorySourceFact $f): BigDecimal => $sum->plus($f->amount), BigDecimal::zero());
        $baseAmount = $facts->reduce(fn (BigDecimal $sum, InventorySourceFact $f): BigDecimal => $sum->plus($f->base_amount), BigDecimal::zero());
        $currencyIds = $facts->pluck('currency_id')->unique();
        if ($currencyIds->count() > 1) {
            throw new DomainInvariantException("Item {$item->code}'s summarised opening lines at one location mix currencies; split them into separate batches so each synthetic layer has one currency.", 'currency_id');
        }
        [$currencyId, $fxRate] = $this->currencyFor($currencyIds->first(), $first->fx_rate, $company);
        $unitCost = $amount->dividedBy($qty, 4, RoundingMode::HALF_UP);
        $baseUnitCost = $baseAmount->dividedBy($qty, 4, RoundingMode::HALF_UP);
        $anchor = $facts->sortBy('id')->first();

        $move = StockMove::query()->create([
            'movement_kind' => 'RECEIPT', 'item_id' => $item->id, 'from_location_id' => null, 'to_location_id' => $location->id,
            'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $cutoverAt,
            'source_type' => 'INVENTORY_CUTOVER_SYNTHETIC', 'source_id' => $anchor->id,
            'unit_cost' => (string) $unitCost, 'currency_id' => $currencyId, 'fx_rate' => $fxRate,
            'batch_id' => $batch?->id, 'serial_no' => null,
        ]);
        StockLayer::query()->create([
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty_received' => (string) $qty, 'qty_remaining' => (string) $qty,
            'unit_cost' => (string) $unitCost, 'currency_id' => $currencyId, 'fx_rate' => $fxRate,
            'base_unit_cost' => (string) $baseUnitCost, 'received_at' => $cutoverAt,
            'source_type' => 'INVENTORY_CUTOVER_SYNTHETIC', 'source_id' => $anchor->id,
            'batch_id' => $batch?->id, 'serial_no' => null,
        ]);
        $accountId = $item->resolveInventoryAccountId();
        if ($accountId === null) {
            throw new DomainInvariantException('A stock receipt requires a resolved inventory account.', 'item_id');
        }
        $move->valuationEntries()->create([
            'avco_pool_id' => null, 'pool_effect' => null, 'qty' => (string) $qty, 'unit_cost' => (string) $unitCost,
            'amount' => (string) $amount, 'base_amount' => (string) $baseAmount, 'account_id' => $accountId,
        ]);
    }

    private function location(int $locationId): Location
    {
        $location = Location::query()->with('warehouse')->lockForUpdate()->findOrFail($locationId);
        if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
            throw new DomainInvariantException('Inventory cutover can only post opening stock into an active stock-holding location.', 'location_id');
        }

        return $location;
    }

    /** @return array{0:?StockBatch, 1:?string} */
    private function resolveIdentity(Item $item, InventorySourceFact $fact): array
    {
        $batchNo = $this->identity->normalisedBatchNo(['batch_no' => $fact->batch_no]);
        $serialNo = $this->identity->normalisedSerialNo(['serial_no' => $fact->serial_no]);
        $this->identity->validateReceiptTracking($item, BigDecimal::of($fact->qty), $batchNo, $serialNo);
        $batch = $batchNo === null ? null : $this->identity->resolveOrCreateBatch($item, $batchNo);

        return [$batch, $serialNo];
    }

    /** @return array{0:int, 1:string} */
    private function currencyFor(?int $factCurrencyId, ?string $factFxRate, Company $company): array
    {
        if ($factCurrencyId !== null) {
            return [$factCurrencyId, (string) $factFxRate];
        }
        if ($company->base_currency_id === null) {
            throw new DomainInvariantException('A company base currency is required to post an opening state without an explicit source currency.', 'currency_id');
        }

        return [$company->base_currency_id, '1.000000000'];
    }

    private function identityKey(string $method, ?string $scope, ?string $poolIdentity): string
    {
        return $method.'|'.($scope ?? '').'|'.($poolIdentity ?? '');
    }
}
