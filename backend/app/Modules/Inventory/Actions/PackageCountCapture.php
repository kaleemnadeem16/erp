<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockMove;

/**
 * What a grouped physical count line captures when it is added or refreshed (ADR 0092 §1): the scope's latest package evidence and its movement
 * watermark. Posting later proves the stock has not moved since; a count is never silently rebased onto newer stock.
 */
final class PackageCountCapture
{
    /** @return array{grouped:true, package_operation_id:int|null, package_watermark:int|null} */
    public function __invoke(Item $item, int $locationId, ?int $batchId, ?string $serialNo, string $bookQty, User $actor): array
    {
        if ($serialNo !== null) {
            throw new DomainInvariantException('Serial stock cannot be counted under grouped package tracking.', 'serial_no');
        }
        if (! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package-state view authority is required to count an item with grouped package tracking.');
        }
        $latest = PackageOperation::query()->where(['item_id' => $item->id, 'location_id' => $locationId, 'batch_id' => $batchId])
            ->whereIn('evidence_kind', ['COUNTED', 'OPEN', 'UNPACK', 'ISSUE', 'TRANSFER_OUT', 'TRANSFER_IN', 'RECEIPT_IN', 'COUNT_VARIANCE', 'RECEIPT_REVERSAL'])->orderByDesc('id')->first();
        $watermark = StockMove::query()->where(['item_id' => $item->id, 'batch_id' => $batchId, 'serial_no' => null])
            ->where(fn ($q) => $q->where('to_location_id', $locationId)->orWhere('from_location_id', $locationId))->max('id');
        if (bccomp($bookQty, '0', 4) === 0) {
            return ['grouped' => true, 'package_operation_id' => null, 'package_watermark' => $watermark === null ? null : (int) $watermark];
        }
        if ($latest === null) {
            throw new DomainInvariantException('This location holds package-tracked stock without package evidence: a counted checkpoint is required first.');
        }
        app(PackageDrawPlanner::class)->fresh($latest->id, $actor);

        return ['grouped' => true, 'package_operation_id' => $latest->id, 'package_watermark' => (int) $latest->stock_move_watermark];
    }
}
