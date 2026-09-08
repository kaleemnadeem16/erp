<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Posts every non-zero-variance line's book-vs-counted difference as a real
 * stock movement, routed through PostInventoryMovement — the same boundary
 * every other receipt/issue posts through, not a bespoke poster. A positive
 * variance (found more) posts as a receipt at the reviewer-supplied
 * found_unit_cost, required per line and never inferred automatically. A
 * negative variance (found less) posts as an ordinary issue with no
 * override_request_id: the write-off quantity is always the exact amount
 * the count's own book_qty snapshot recorded, so it can only fail to be
 * fully covered if something else moved this exact stock identity after the
 * count was taken — PostInventoryMovement's own insufficient-stock guard
 * catches that race instead of this Action re-deriving it.
 *
 * Requires an actor distinct from whoever created the physical count,
 * mirroring ApproveInventoryCutover's own separation of duties.
 */
final class PostPhysicalCount
{
    public function __construct(private readonly PostInventoryMovement $movements) {}

    /** @param array<int, string> $foundCosts physical_count_line_id => unit cost, required for every positive-variance line */
    public function __invoke(PhysicalCount $count, array $foundCosts, User $actor): PhysicalCount
    {
        if (! $actor->can('inventory.physical_count.post')) {
            throw new DomainInvariantException('The actor is not permitted to post a physical count.', 'posted_by');
        }

        return DB::transaction(function () use ($count, $foundCosts, $actor): PhysicalCount {
            $count = PhysicalCount::query()->whereKey($count->id)->lockForUpdate()->firstOrFail();
            if ($count->status === PhysicalCountStatus::Posted) {
                return $count->refresh()->load('lines');
            }
            if ($count->status !== PhysicalCountStatus::Draft) {
                throw new DomainInvariantException('Only a draft physical count can be posted.', 'status');
            }
            if ($actor->id === $count->created_by) {
                throw new DomainInvariantException('The actor who created this physical count cannot also post it.', 'posted_by');
            }

            $lines = PhysicalCountLine::query()->where('physical_count_id', $count->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new DomainInvariantException('A physical count needs at least one line before it can be posted.', 'status');
            }
            if ($lines->contains(fn (PhysicalCountLine $line): bool => $line->counted_qty === null)) {
                throw new DomainInvariantException('Every line must be counted before the physical count can be posted.', 'counted_qty');
            }

            $company = Company::query()->findOrFail(app(CompanyContext::class)->id());
            if ($company->base_currency_id === null) {
                throw new DomainInvariantException('A company base currency is required to post a physical count.', 'currency_id');
            }

            foreach ($lines as $line) {
                $this->postLine($line, $company, $foundCosts[$line->id] ?? null);
            }

            $count->update(['status' => PhysicalCountStatus::Posted, 'posted_by' => $actor->id, 'posted_at' => now()]);

            return $count->refresh()->load('lines');
        });
    }

    private function postLine(PhysicalCountLine $line, Company $company, ?string $foundCost): void
    {
        $variance = BigDecimal::of($line->counted_qty)->minus($line->book_qty);
        if ($variance->isZero()) {
            return;
        }
        $item = Item::query()->findOrFail($line->item_id);
        $batchNo = $line->batch_id === null ? null : StockBatch::query()->findOrFail($line->batch_id)->code;

        if ($variance->isPositive()) {
            if ($foundCost === null || BigDecimal::of($foundCost)->isLessThanOrEqualTo(0)) {
                throw new DomainInvariantException("Line {$line->id} has a positive variance and requires a defensible found-stock unit cost before posting.", 'found_unit_cost');
            }
            $move = $this->movements->__invoke([
                'item_id' => $item->id, 'location_id' => $line->location_id, 'qty' => (string) $variance,
                'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
                'source_type' => 'PHYSICAL_COUNT_LINE', 'source_id' => $line->id,
                'unit_cost' => $foundCost, 'currency_id' => $company->base_currency_id, 'fx_rate' => '1.000000000',
                'base_unit_cost' => $foundCost, 'batch_no' => $batchNo, 'serial_no' => $line->serial_no,
            ]);
            $line->update(['found_unit_cost' => $foundCost, 'posted_stock_move_id' => $move->id]);

            return;
        }

        $move = $this->movements->issue([
            'item_id' => $item->id, 'location_id' => $line->location_id, 'qty' => (string) $variance->abs(),
            'uom_id' => $item->stock_uom_id, 'moved_at' => now(),
            'source_type' => 'PHYSICAL_COUNT_LINE', 'source_id' => $line->id,
            'batch_no' => $batchNo, 'serial_no' => $line->serial_no,
        ]);
        $line->update(['posted_stock_move_id' => $move->id]);
    }
}
