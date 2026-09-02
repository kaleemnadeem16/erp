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
