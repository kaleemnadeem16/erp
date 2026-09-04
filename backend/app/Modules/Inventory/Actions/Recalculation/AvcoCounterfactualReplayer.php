<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Recalculation;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\Movements\AvcoPoolManager;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockValuationEntry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Computes what an AVCO pool's value would be if a proposed receipt had been
 * posted at its true chronological position (T) instead of being blocked as a
 * backdating conflict — see docs/domain/stock-recalculation.md. Pure/read-only:
 * no locks, no writes. Internal to the recalculation-request Actions.
 *
 * Deliberately refuses (DomainInvariantException) rather than guess whenever
 * the pool had any negative-stock shortage activity in the replay window —
 * faithfully replaying ShortageSettler's settlement/provisional-cost rules in
 * a parallel pure form is out of scope; producing a subtly wrong number a
 * human then approves is worse than a clear "not yet supported."
 */
final class AvcoCounterfactualReplayer
{
    public function __construct(private readonly AvcoPoolManager $pools) {}

    public function replay(Item $item, Location $location, ?StockBatch $batch, BigDecimal $proposedQty, BigDecimal $proposedUnitCost, CarbonInterface $movedAt): ImpactPreview
    {
        $pool = $this->pools->resolveExisting($item, $location, $batch);
        if ($pool === null) {
            throw new DomainInvariantException('No AVCO pool exists yet for this item/location — nothing to recalculate.', 'item_id');
        }

        [$qtyAtT, $valueAtT] = $this->stateAsOf((int) $pool->id, $movedAt);
        if ($qtyAtT->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException("The pool was already non-positive at the proposed date — recalculation isn't yet supported for this case.", 'moved_at');
        }
        if ($qtyAtT->plus($proposedQty)->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException("The proposed receipt doesn't clear the pool's existing shortfall — recalculation isn't yet supported for this case.", 'qty');
        }
        if ($this->hasShortageCreatedAfter((int) $pool->id, $movedAt)) {
            throw new DomainInvariantException("This pool had negative-stock shortage activity after the proposed date — recalculation isn't yet supported for this case.", 'moved_at');
        }

        $runningQty = $qtyAtT->plus($proposedQty);
        $runningValue = $valueAtT->plus($proposedQty->multipliedBy($proposedUnitCost)->toScale(4, RoundingMode::HALF_UP));

        $laterEntries = StockValuationEntry::query()
            ->join('stock_moves', 'stock_moves.id', '=', 'stock_valuation_entries.stock_move_id')
            ->where('stock_valuation_entries.avco_pool_id', $pool->id)
            ->where('stock_moves.moved_at', '>', $movedAt)
            ->orderBy('stock_moves.moved_at')->orderBy('stock_valuation_entries.id')
            ->select('stock_valuation_entries.*')
            ->get();

        foreach ($laterEntries as $entry) {
            if (! in_array($entry->pool_effect, ['SOURCE_OUT', 'DESTINATION_IN'], true)) {
                // A settlement/repair (VALUATION_ADJUSTMENT) touched this pool in
                // the window — its provisional-cost rules aren't replayed here.
                throw new DomainInvariantException("This pool had a settlement repair after the proposed date — recalculation isn't yet supported for this case.", 'moved_at');
            }
            if ($entry->pool_effect === 'DESTINATION_IN') {
                // A receipt's value doesn't depend on the pool average, so it's
                // identical in both timelines — passthrough.
                $runningValue = $runningValue->plus(BigDecimal::of($entry->base_amount));
                $runningQty = $runningQty->plus(BigDecimal::of($entry->qty));

                continue;
            }
            $outboundQty = BigDecimal::of($entry->qty)->abs();
            if ($outboundQty->isGreaterThanOrEqualTo($runningQty)) {
                throw new DomainInvariantException("The counterfactual pool would also run non-positive after the proposed date — recalculation isn't yet supported for this case.", 'moved_at');
            }
            // The one place the counterfactual actually diverges from what was
            // posted: re-derive this outflow's cost from the counterfactual
            // running average instead of reusing its recorded base_amount.
            $averageBefore = $runningValue->dividedBy($runningQty, 4, RoundingMode::HALF_UP);
            $runningValue = $runningValue->minus($outboundQty->multipliedBy($averageBefore)->toScale(4, RoundingMode::HALF_UP));
            $runningQty = $runningQty->minus($outboundQty);
        }

        return new ImpactPreview(
            pool: $pool,
            originalQtyOnHand: BigDecimal::of($pool->qty_on_hand),
            originalInventoryValue: BigDecimal::of($pool->inventory_value),
            originalAvgUnitCost: BigDecimal::of($pool->avg_unit_cost),
            counterfactualQtyOnHand: $runningQty,
            counterfactualInventoryValue: $runningValue,
            counterfactualAvgUnitCost: $runningValue->dividedBy($runningQty, 4, RoundingMode::HALF_UP),
        );
    }

    /** @return array{0: BigDecimal, 1: BigDecimal} qty, value */
    private function stateAsOf(int $poolId, CarbonInterface $movedAt): array
    {
        $row = DB::table('stock_valuation_entries')
            ->join('stock_moves', 'stock_moves.id', '=', 'stock_valuation_entries.stock_move_id')
            ->where('stock_valuation_entries.avco_pool_id', $poolId)
            ->where('stock_moves.moved_at', '<=', $movedAt)
            ->selectRaw('COALESCE(SUM(stock_valuation_entries.qty), 0) as qty_sum, COALESCE(SUM(stock_valuation_entries.base_amount), 0) as value_sum')
            ->first();

        return [BigDecimal::of((string) $row->qty_sum), BigDecimal::of((string) $row->value_sum)];
    }

    private function hasShortageCreatedAfter(int $poolId, CarbonInterface $movedAt): bool
    {
        return NegativeStockShortage::query()
            ->where('avco_pool_id', $poolId)
            ->whereExists(function ($query) use ($movedAt): void {
                $query->select(DB::raw(1))->from('stock_moves')
                    ->whereColumn('stock_moves.id', 'negative_stock_shortages.source_move_id')
                    ->where('stock_moves.moved_at', '>', $movedAt);
            })
            ->exists();
    }
}
