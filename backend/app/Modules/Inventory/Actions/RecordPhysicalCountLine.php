<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use App\Modules\Inventory\Support\StockQuantity;
use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Support\Facades\DB;

/**
 * Records a count for one line — the first count, or a recount. Every call
 * appends an immutable PhysicalCountLineEntry (the audit trail) and the line
 * itself always mirrors the latest one: "recount replaces the working
 * count," not a second competing line. Not idempotent by design — calling
 * this twice with the same quantity records two attempts, not one.
 */
final class RecordPhysicalCountLine
{
    public function __invoke(PhysicalCountLine $line, string $countedQty, User $actor): PhysicalCountLine
    {
        if (! $actor->can('inventory.physical_count.count')) {
            throw new DomainInvariantException('The actor is not permitted to record a physical count.', 'counted_by');
        }
        WarehouseScope::requireWarehouse($actor, 'inventory.physical_count.count', (int) PhysicalCount::query()->whereKey($line->physical_count_id)->value('warehouse_id'), 'record a count');

        return DB::transaction(function () use ($line, $countedQty, $actor): PhysicalCountLine {
            $line = PhysicalCountLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $count = PhysicalCount::query()->whereKey($line->physical_count_id)->lockForUpdate()->firstOrFail();
            if ($count->status !== PhysicalCountStatus::Draft) {
                throw new DomainInvariantException('A line can only be counted while its physical count is draft.', 'status');
            }

            if ($line->grouped) {
                throw new DomainInvariantException('This item uses grouped package tracking: record the counted composition (sealed packages and loose quantity) instead of a single quantity.', 'counted_qty');
            }

            $qty = StockQuantity::signed($countedQty, 'counted_qty');
            if ($qty->isNegative()) {
                throw new DomainInvariantException('Counted quantity cannot be negative.', 'counted_qty');
            }
            if ($line->serial_no !== null && ! $qty->isEqualTo(0) && ! $qty->isEqualTo(1)) {
                throw new DomainInvariantException('A serial-identified line can only be counted as present (1) or absent (0).', 'counted_qty');
            }

            $now = now();
            $line->entries()->create(['counted_qty' => (string) $qty, 'counted_by' => $actor->id, 'counted_at' => $now]);
            $line->update(['counted_qty' => (string) $qty, 'counted_by' => $actor->id, 'counted_at' => $now]);

            return $line->refresh();
        });
    }
}
