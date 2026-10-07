<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Models\PhysicalCountLineComposition;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Support\Facades\DB;

/**
 * Re-snapshots a stale grouped count line against current stock and evidence and clears its counted composition, so it must be recounted (ADR 0092 §2).
 * A count is never rebased onto newer stock silently. Only a draft count's grouped line; needs the authority that adds lines.
 */
final class RefreshPhysicalCountLine
{
    public function __invoke(PhysicalCountLine $line, User $actor): PhysicalCountLine
    {
        if (! $actor->can('inventory.physical_count.create')) {
            throw new DomainInvariantException('The actor is not permitted to refresh a physical count line.', 'created_by');
        }
        WarehouseScope::requireWarehouse($actor, 'inventory.physical_count.create', (int) PhysicalCount::query()->whereKey($line->physical_count_id)->value('warehouse_id'), 'refresh a count line');

        return DB::transaction(function () use ($line, $actor): PhysicalCountLine {
            $line = PhysicalCountLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $count = PhysicalCount::query()->whereKey($line->physical_count_id)->lockForUpdate()->firstOrFail();
            if ($count->status !== PhysicalCountStatus::Draft || ! $line->grouped) {
                throw new DomainInvariantException('Only a grouped line of a draft physical count can be refreshed.', 'status');
            }
            $item = Item::query()->findOrFail($line->item_id);
            $book = (string) StockLayer::query()->where(['item_id' => $item->id, 'location_id' => $line->location_id, 'batch_id' => $line->batch_id, 'serial_no' => null])->sum('qty_remaining');
            $capture = app(PackageCountCapture::class)($item, $line->location_id, $line->batch_id, $line->serial_no, $book, $actor);
            PhysicalCountLineComposition::query()->where('physical_count_line_id', $line->id)->delete();
            $line->update(['book_qty' => $book, 'package_operation_id' => $capture['package_operation_id'], 'package_watermark' => $capture['package_watermark'],
                'counted_qty' => null, 'counted_by' => null, 'counted_at' => null]);

            return $line->refresh();
        });
    }
}
