<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\Movements\StockIdentity;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Support\Facades\DB;

/**
 * Adds one line to a draft PhysicalCount, snapshotting its book_qty as the
 * live SUM(stock_layers.qty_remaining) at the exact (item, location, batch,
 * serial) identity — the same physical-on-hand source of truth every
 * valuation method already writes to, regardless of which method is
 * official for the item. The snapshot is a read, never locked: drift
 * between it and the true position at post time is exactly what a count
 * exists to catch, not something to prevent here.
 */
final class AddPhysicalCountLine
{
    public function __construct(private readonly StockIdentity $identity) {}

    /** @param array{item_id:int, location_id:int, batch_no?:string|null, serial_no?:string|null} $input */
    public function __invoke(PhysicalCount $count, array $input, User $actor): PhysicalCountLine
    {
        if (! $actor->can('inventory.physical_count.create')) {
            throw new DomainInvariantException('The actor is not permitted to add a physical count line.', 'created_by');
        }
        WarehouseScope::requireWarehouse($actor, 'inventory.physical_count.create', (int) $count->warehouse_id, 'add a physical count line');

        return DB::transaction(function () use ($count, $input, $actor): PhysicalCountLine {
            $count = PhysicalCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status !== PhysicalCountStatus::Draft) {
                throw new DomainInvariantException('Lines can only be added to a draft physical count.', 'status');
            }

            $item = Item::query()->findOrFail($input['item_id']);
            if (! $item->is_stocked) {
                throw new DomainInvariantException('Only a stocked item can be physically counted.', 'item_id');
            }
            $location = Location::query()->with('warehouse')->findOrFail($input['location_id']);
            if ($location->warehouse_id !== $count->warehouse_id) {
                throw new DomainInvariantException('The location must belong to the physical count\'s warehouse.', 'location_id');
            }
            if (! $location->active || ! $location->allows_stock || ! $location->warehouse->active) {
                throw new DomainInvariantException('Only an active stock-holding location can be physically counted.', 'location_id');
            }

            $batchNo = $this->identity->normalisedBatchNo($input);
            $serialNo = $this->identity->normalisedSerialNo($input);
            $this->validateTrackingShape($item, $batchNo, $serialNo);
            $batch = $batchNo === null ? null : $this->identity->resolveOrCreateBatch($item, $batchNo);

            $existing = PhysicalCountLine::query()
                ->where('physical_count_id', $count->id)->where('item_id', $item->id)->where('location_id', $location->id)
                ->where('batch_id', $batch?->id)->where('serial_no', $serialNo)->first();
            if ($existing !== null) {
                return $existing;
            }

            $bookQty = StockLayer::query()->where('item_id', $item->id)->where('location_id', $location->id)
                ->where('batch_id', $batch?->id)->where('serial_no', $serialNo)->sum('qty_remaining');

            $capture = PackageWriteScope::isAuthoritative($item->id)
                ? app(PackageCountCapture::class)($item, $location->id, $batch?->id, $serialNo, (string) $bookQty, $actor)
                : [];

            return PhysicalCountLine::query()->create(array_merge([
                'physical_count_id' => $count->id, 'item_id' => $item->id, 'location_id' => $location->id,
                'batch_id' => $batch?->id, 'serial_no' => $serialNo, 'book_qty' => (string) $bookQty,
            ], $capture));
        });
    }

    /**
     * Count lines allow a zero book_qty (the item may have never been
     * recorded here at all — a genuine "found extra" scenario), so this
     * checks batch/serial *shape* only, unlike
     * StockIdentity::validateReceiptTracking()'s stricter qty=1 SERIAL rule.
     */
    private function validateTrackingShape(Item $item, ?string $batchNo, ?string $serialNo): void
    {
        if ($item->tracking === ItemTracking::None && ($batchNo !== null || $serialNo !== null)) {
            throw new DomainInvariantException('Untracked items cannot carry batch or serial identity.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Batch && ($batchNo === null || $serialNo !== null)) {
            throw new DomainInvariantException('Batch-tracked items require a batch and cannot carry a serial.', 'batch_no');
        }
        if ($item->tracking === ItemTracking::Serial && ($serialNo === null || $batchNo !== null)) {
            throw new DomainInvariantException('Serial-tracked items require a serial and cannot carry a batch.', 'serial_no');
        }
    }
}
