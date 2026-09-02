<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * The sole boundary allowed to create physical and valuation stock state.
 * This first slice implements inbound movements; issues and transfers extend
 * this same Action rather than introducing another write path.
 */
final class PostInventoryMovement
{
    /**
     * @param  array{item_id:int, location_id:int, qty:string, uom_id:int, moved_at:mixed, source_type:string, source_id:int, unit_cost:string, currency_id:int, fx_rate:string, base_unit_cost:string, batch_no?:string|null, serial_no?:string|null, amount?:string, base_amount?:string}  $input
     *
     * amount/base_amount are optional pinned ledger totals. When the caller has an
     * authoritative total that isn't exactly qty × unit_cost — e.g. a UoM-converted
     * receipt, where unit_cost is a rounded per-stock-unit figure and re-deriving the
     * total from it would lose a rounding residual — pass it explicitly here rather
     * than letting it be silently recomputed. Defaults to qty × unit_cost.
     */
    public function __invoke(array $input): StockMove
    {
        return DB::transaction(function () use ($input): StockMove {
            $existing = StockMove::query()->where('source_type', $input['source_type'])->where('source_id', $input['source_id'])->first();
            if ($existing !== null) {
                return $existing->load('valuationEntry');
            }

            $item = Item::query()->findOrFail($input['item_id']);
            if (! StockMove::query()->where('item_id', $item->id)->exists()) {
                // Serialise only first-use policy locking. Established independent
                // AVCO pools must remain able to post concurrently per ADR 0039.
                $item = Item::query()->lockForUpdate()->findOrFail($input['item_id']);
            }
            $location = Location::query()->with('warehouse')->findOrFail($input['location_id']);
            Currency::query()->findOrFail($input['currency_id']);
            if (! $item->is_stocked || $item->stock_uom_id !== $input['uom_id']) {
                throw new DomainInvariantException('Stock receipts require a stocked item in its pinned stock UoM.', 'uom_id');
            }
            if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                throw new DomainInvariantException('Stock can only be received into an active stock-holding location.', 'location_id');
            }
            if (StockMove::query()->where('item_id', $item->id)->where('moved_at', '>', $input['moved_at'])->exists()) {
                throw new DomainInvariantException('This receipt conflicts with later valuation history and requires the controlled backdating workflow.', 'moved_at');
            }

            $qty = BigDecimal::of($input['qty']);
            $unitCost = BigDecimal::of($input['unit_cost']);
            $baseUnitCost = BigDecimal::of($input['base_unit_cost']);
            $batchNo = isset($input['batch_no']) ? strtolower(trim((string) $input['batch_no'])) : null;
            $serialNo = isset($input['serial_no']) ? trim((string) $input['serial_no']) : null;
            $this->validateTracking($item, $qty, $batchNo, $serialNo);

            $batch = $batchNo === null ? null : $this->resolveBatch($item, $batchNo);
            $pool = $item->valuation_method === ValuationMethod::Avco
                ? $this->lockAvcoPool($item, $location, $batch)
                : null;

            $move = StockMove::query()->create([
                'item_id' => $item->id, 'from_location_id' => null, 'to_location_id' => $location->id,
                'qty' => (string) $qty, 'uom_id' => $item->stock_uom_id, 'moved_at' => $input['moved_at'],
                'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
                'unit_cost' => (string) $unitCost, 'currency_id' => $input['currency_id'], 'fx_rate' => $input['fx_rate'],
                'batch_id' => $batch?->id, 'serial_no' => $serialNo,
            ]);

            StockLayer::query()->create([
                'item_id' => $item->id, 'location_id' => $location->id,
                'qty_received' => (string) $qty, 'qty_remaining' => (string) $qty,
                'unit_cost' => (string) $unitCost, 'currency_id' => $input['currency_id'],
                'fx_rate' => $input['fx_rate'], 'base_unit_cost' => (string) $baseUnitCost,
                'received_at' => $input['moved_at'], 'source_type' => $input['source_type'], 'source_id' => $input['source_id'],
                'batch_id' => $batch?->id, 'serial_no' => $serialNo,
            ]);

            $transactionAmount = isset($input['amount'])
                ? BigDecimal::of($input['amount'])
                : $qty->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP);
            $baseAmount = isset($input['base_amount'])
                ? BigDecimal::of($input['base_amount'])
                : $qty->multipliedBy($baseUnitCost)->toScale(4, RoundingMode::HALF_UP);
            if ($pool !== null) {
                $newQty = BigDecimal::of($pool->qty_on_hand)->plus($qty);
                $newValue = BigDecimal::of($pool->inventory_value)->plus($baseAmount)->toScale(4, RoundingMode::HALF_UP);
                $newAverage = $newValue->dividedBy($newQty, 4, RoundingMode::HALF_UP);
                $pool->update([
                    'qty_on_hand' => (string) $newQty, 'inventory_value' => (string) $newValue,
                    'avg_unit_cost' => (string) $newAverage, 'base_avg_unit_cost' => (string) $newAverage,
                    'last_valid_avg_unit_cost' => (string) $newAverage, 'status' => 'ACTIVE',
                ]);
            }

            $accountId = $item->resolveInventoryAccountId();
            if ($accountId === null) {
                throw new DomainInvariantException('A stock receipt requires a resolved inventory account.', 'item_id');
            }
            $move->valuationEntry()->create([
                'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'DESTINATION_IN',
                'qty' => (string) $qty, 'unit_cost' => (string) $unitCost,
                'amount' => (string) $transactionAmount, 'base_amount' => (string) $baseAmount,
                'account_id' => $accountId,
            ]);

            return $move->load('valuationEntry');
        });
    }

    /**
     * Reverses one specific prior inbound movement, identified by its own source
     * key, by its full originally-received quantity. Requires the layer it created
     * to be fully untouched (qty_remaining === qty_received) and, for AVCO items,
     * the resolved pool to still hold at least this quantity — checked and mutated
     * atomically here, not pre-checked by the caller, so multiple lines of one
     * reversal that share a pool correctly aggregate against each other rather than
     * each being validated against a stale pre-mutation snapshot.
     *
     * Deliberately narrow: exactly one known source, full original quantity only,
     * no oldest-layer selection and no negative-stock override branching. This is
     * not the general outbound/issue engine — that remains unbuilt.
     */
    public function reverseReceipt(
        string $originalSourceType,
        int $originalSourceId,
        string $reversalSourceType,
        int $reversalSourceId,
        mixed $reversedAt,
    ): StockMove {
        return DB::transaction(function () use ($originalSourceType, $originalSourceId, $reversalSourceType, $reversalSourceId, $reversedAt): StockMove {
            $existing = StockMove::query()->where('source_type', $reversalSourceType)->where('source_id', $reversalSourceId)->first();
            if ($existing !== null) {
                return $existing->load('valuationEntry');
            }

            $original = StockMove::query()->with('valuationEntry')
                ->where('source_type', $originalSourceType)->where('source_id', $originalSourceId)->first();
            if ($original === null || $original->from_location_id !== null) {
                throw new DomainInvariantException('No posted inbound movement found to reverse for the given source.', 'source_id');
            }

            $layer = StockLayer::query()->where('source_type', $originalSourceType)->where('source_id', $originalSourceId)
                ->lockForUpdate()->firstOrFail();
            $qty = BigDecimal::of($original->qty);
            if (! BigDecimal::of($layer->qty_remaining)->isEqualTo(BigDecimal::of($layer->qty_received))) {
                throw new DomainInvariantException('This receipt has already been partly consumed and cannot be reversed.', 'source_id');
            }

            $item = Item::query()->findOrFail($original->item_id);
            $pool = null;
            if ($item->valuation_method === ValuationMethod::Avco) {
                $location = Location::query()->findOrFail($original->to_location_id);
                $batch = $original->batch_id === null ? null : StockBatch::query()->find($original->batch_id);
                $pool = $this->lockAvcoPool($item, $location, $batch);
                if (BigDecimal::of($pool->qty_on_hand)->isLessThan($qty)) {
                    throw new DomainInvariantException('This receipt has already left its valuation pool and cannot be reversed.', 'source_id');
                }
            }

            $move = StockMove::query()->create([
                'item_id' => $item->id, 'from_location_id' => $original->to_location_id, 'to_location_id' => null,
                'qty' => (string) $qty, 'uom_id' => $original->uom_id, 'moved_at' => $reversedAt,
                'source_type' => $reversalSourceType, 'source_id' => $reversalSourceId,
                'unit_cost' => $original->unit_cost, 'currency_id' => $original->currency_id, 'fx_rate' => $original->fx_rate,
                'batch_id' => $original->batch_id, 'serial_no' => $original->serial_no,
            ]);

            $layer->update(['qty_remaining' => (string) BigDecimal::of($layer->qty_remaining)->minus($qty)]);

            if ($pool !== null) {
                $newQty = BigDecimal::of($pool->qty_on_hand)->minus($qty);
                $newValue = BigDecimal::of($pool->inventory_value)->minus(BigDecimal::of($original->valuationEntry->base_amount))->toScale(4, RoundingMode::HALF_UP);
                // A clean full reversal reaching exactly zero is an ordinary drawdown,
                // not an ADR 0037 negative-stock excursion — never FREEZE for this, and
                // never overwrite last_valid_avg_unit_cost, whose whole purpose is
                // surviving zero/negative periods for later provisional costing.
                $newAverage = $newQty->isZero() ? BigDecimal::zero() : $newValue->dividedBy($newQty, 4, RoundingMode::HALF_UP);
                $pool->update([
                    'qty_on_hand' => (string) $newQty, 'inventory_value' => (string) $newValue,
                    'avg_unit_cost' => (string) $newAverage, 'base_avg_unit_cost' => (string) $newAverage,
                    'last_valid_avg_unit_cost' => $newQty->isZero() ? $pool->last_valid_avg_unit_cost : (string) $newAverage,
                ]);
            }

            $move->valuationEntry()->create([
                'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'SOURCE_OUT',
                'qty' => (string) $qty->negated(), 'unit_cost' => $original->valuationEntry->unit_cost,
                'amount' => (string) BigDecimal::of($original->valuationEntry->amount)->negated(),
                'base_amount' => (string) BigDecimal::of($original->valuationEntry->base_amount)->negated(),
                'account_id' => $original->valuationEntry->account_id,
            ]);

            return $move->load('valuationEntry');
        });
    }

    private function validateTracking(Item $item, BigDecimal $qty, ?string $batchNo, ?string $serialNo): void
    {
        if ($item->tracking === ItemTracking::None && ($batchNo !== null || $serialNo !== null)) {
            throw new DomainInvariantException('Untracked items cannot carry batch or serial identity.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Batch && ($batchNo === null || $serialNo !== null)) {
            throw new DomainInvariantException('Batch-tracked items require a batch and cannot carry a serial.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Serial && ($serialNo === null || ! $qty->isEqualTo(1))) {
            throw new DomainInvariantException('Serial-tracked receipts require exactly one unit and one serial per line.', 'serial_no');
        }
        if ($item->valuation_method === ValuationMethod::Specific && $item->tracking !== ItemTracking::Serial) {
            throw new DomainInvariantException('SPECIFIC valuation requires SERIAL tracking.', 'item_id');
        }
    }

    private function lockAvcoPool(Item $item, Location $location, ?StockBatch $batch): AvcoPool
    {
        $scope = $item->avco_pool_scope;
        if ($scope === AvcoPoolScope::CompanyItemBatch && $batch === null) {
            throw new DomainInvariantException('Batch-valued AVCO requires a captured batch identity.', 'batch_no');
        }

        $identity = ['item_id' => $item->id, 'scope' => $scope->value];
        $identity['warehouse_id'] = $scope === AvcoPoolScope::WarehouseItem ? $location->warehouse_id : null;
        $identity['batch_id'] = $scope === AvcoPoolScope::CompanyItemBatch ? $batch->id : null;
        DB::table('avco_pools')->insertOrIgnore(array_merge($identity, [
            'company_id' => app(CompanyContext::class)->id(), 'qty_on_hand' => 0, 'inventory_value' => 0,
            'avg_unit_cost' => 0, 'base_avg_unit_cost' => 0, 'last_valid_avg_unit_cost' => 0,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return AvcoPool::query()->where($identity)->lockForUpdate()->firstOrFail();
    }

    private function resolveBatch(Item $item, string $batchNo): StockBatch
    {
        DB::table('stock_batches')->insertOrIgnore([
            'company_id' => app(CompanyContext::class)->id(), 'item_id' => $item->id, 'code' => $batchNo,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return StockBatch::query()->where('item_id', $item->id)->where('code', $batchNo)->firstOrFail();
    }
}
