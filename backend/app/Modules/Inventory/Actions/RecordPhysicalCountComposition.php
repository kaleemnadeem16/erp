<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\PhysicalCountLine;
use App\Modules\Inventory\Models\PhysicalCountLineComposition;
use App\Modules\Inventory\Support\PackageTrackedLevels;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use App\Modules\Inventory\Support\WarehouseScope;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Support\Facades\DB;

/**
 * Records what is on the shelf for a grouped physical count line: whole sealed packages of the item's tracked levels plus one open/loose quantity
 * (ADR 0092). The counted quantity is derived from it. A recount replaces the working composition; every attempt is kept in the line's audit entries.
 * An empty composition means nothing was found. Blind counting is unchanged: this shows the counter nothing of the book.
 */
final class RecordPhysicalCountComposition
{
    /**
     * @param  list<array{packaging_id?:int|null, count?:int, quantity?:string}>  $composition
     */
    public function __invoke(PhysicalCountLine $line, array $composition, User $actor): PhysicalCountLine
    {
        if (! $actor->can('inventory.physical_count.count')) {
            throw new DomainInvariantException('The actor is not permitted to record a physical count.', 'counted_by');
        }
        WarehouseScope::requireWarehouse($actor, 'inventory.physical_count.count', (int) PhysicalCount::query()->whereKey($line->physical_count_id)->value('warehouse_id'), 'record a count');

        return DB::transaction(function () use ($line, $composition, $actor): PhysicalCountLine {
            $line = PhysicalCountLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            $count = PhysicalCount::query()->whereKey($line->physical_count_id)->lockForUpdate()->firstOrFail();
            if ($count->status !== PhysicalCountStatus::Draft) {
                throw new DomainInvariantException('A line can only be counted while its physical count is draft.', 'status');
            }
            if (! $line->grouped) {
                throw new DomainInvariantException('This line is not counted under grouped package tracking; record a quantity instead.', 'counted_qty');
            }

            $sealed = [];
            $loose = BigDecimal::zero();
            foreach ($composition as $row) {
                $packaging = $row['packaging_id'] ?? null;
                if ($packaging !== null) {
                    if ($packaging < 1 || ! isset($row['count']) || $row['count'] < 1 || isset($row['quantity'])) {
                        throw new DomainInvariantException('Sealed packages need a definition and a whole positive count.', 'composition');
                    }
                    $sealed[$packaging] = ($sealed[$packaging] ?? 0) + $row['count'];
                } else {
                    $loose = $loose->plus($this->exact((string) ($row['quantity'] ?? '')));
                }
            }
            PackageTrackedLevels::require($line->item_id, array_keys($sealed), 'a count');

            PhysicalCountLineComposition::query()->where('physical_count_line_id', $line->id)->delete();
            $total = BigDecimal::zero();
            $number = 0;
            foreach ($sealed as $packagingId => $wholeCount) {
                $definition = ItemPackaging::query()->where('item_id', $line->item_id)->findOrFail($packagingId);
                $quantity = BigDecimal::of($definition->stock_quantity)->multipliedBy($wholeCount)->toScale(4);
                PhysicalCountLineComposition::query()->create(['physical_count_line_id' => $line->id, 'line_no' => ++$number,
                    'item_packaging_id' => $packagingId, 'whole_count' => $wholeCount, 'quantity' => (string) $quantity]);
                $total = $total->plus($quantity);
            }
            if ($loose->isGreaterThan(0)) {
                PhysicalCountLineComposition::query()->create(['physical_count_line_id' => $line->id, 'line_no' => ++$number,
                    'item_packaging_id' => null, 'whole_count' => null, 'quantity' => (string) $loose->toScale(4)]);
                $total = $total->plus($loose);
            }

            $now = now();
            $line->entries()->create(['counted_qty' => (string) $total->toScale(4), 'counted_by' => $actor->id, 'counted_at' => $now]);
            $line->update(['counted_qty' => (string) $total->toScale(4), 'counted_by' => $actor->id, 'counted_at' => $now]);

            return $line->refresh();
        });
    }

    private function exact(string $quantity): BigDecimal
    {
        try {
            $value = BigDecimal::of($quantity);
            if ($value->isGreaterThan(0) && $value->getScale() <= 4) {
                return $value->toScale(4);
            }
        } catch (\InvalidArgumentException|MathException $error) {
            // domain error below
        }
        throw new DomainInvariantException('A loose quantity must be positive and no finer than the stock ledger.', 'composition');
    }
}
