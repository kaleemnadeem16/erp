<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Movements;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Batch/serial identity rules for PostInventoryMovement. Internal to the sole
 * movement boundary — never call this from outside that Action.
 */
final class StockIdentity
{
    /** @param array{batch_no?:string|null} $input */
    public function normalisedBatchNo(array $input): ?string
    {
        return isset($input['batch_no']) ? strtolower(trim((string) $input['batch_no'])) : null;
    }

    /** @param array{serial_no?:string|null} $input */
    public function normalisedSerialNo(array $input): ?string
    {
        return isset($input['serial_no']) ? trim((string) $input['serial_no']) : null;
    }

    public function validateReceiptTracking(Item $item, BigDecimal $qty, ?string $batchNo, ?string $serialNo): void
    {
        if ($item->tracking === ItemTracking::None && ($batchNo !== null || $serialNo !== null)) {
            throw new DomainInvariantException('Untracked items cannot carry batch or serial identity.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Batch && ($batchNo === null || $serialNo !== null)) {
            throw new DomainInvariantException('Batch-tracked items require a batch and cannot carry a serial.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Serial && ($serialNo === null || $batchNo !== null || ! $qty->isEqualTo(1))) {
            throw new DomainInvariantException('Serial-tracked receipts require exactly one unit and one serial per line.', 'serial_no');
        }
        if ($item->valuation_method === ValuationMethod::Specific && $item->tracking !== ItemTracking::Serial) {
            throw new DomainInvariantException('SPECIFIC valuation requires SERIAL tracking.', 'item_id');
        }
    }

    public function validateIssueTracking(Item $item, BigDecimal $qty, ?string $batchNo, ?string $serialNo): void
    {
        if ($item->tracking === ItemTracking::None && ($batchNo !== null || $serialNo !== null)) {
            throw new DomainInvariantException('Untracked issues cannot carry batch or serial identity.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Batch && ($batchNo === null || $serialNo !== null)) {
            throw new DomainInvariantException('Batch-tracked issues require a batch and cannot carry a serial.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Serial && ($serialNo === null || $batchNo !== null || ! $qty->isEqualTo(1))) {
            throw new DomainInvariantException('Serial-tracked issues require exactly one unit and one serial.', 'serial_no');
        }
        if ($item->valuation_method === ValuationMethod::Specific && $item->tracking !== ItemTracking::Serial) {
            throw new DomainInvariantException('SPECIFIC valuation requires SERIAL tracking.', 'item_id');
        }
    }

    /** Outbound paths require the batch to already exist for the item. */
    public function findOnHandBatch(Item $item, ?string $batchNo): ?StockBatch
    {
        if ($batchNo === null) {
            return null;
        }
        $batch = StockBatch::query()->where('item_id', $item->id)->where('code', $batchNo)->first();
        if ($batch === null) {
            throw new DomainInvariantException('The requested batch is not on hand for this item.', 'batch_no');
        }

        return $batch;
    }

    /** Inbound receipts create the stable batch identity on first use. */
    public function resolveOrCreateBatch(Item $item, string $batchNo): StockBatch
    {
        DB::table('stock_batches')->insertOrIgnore([
            'company_id' => app(CompanyContext::class)->id(), 'item_id' => $item->id, 'code' => $batchNo,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return StockBatch::query()->where('item_id', $item->id)->where('code', $batchNo)->firstOrFail();
    }
}
