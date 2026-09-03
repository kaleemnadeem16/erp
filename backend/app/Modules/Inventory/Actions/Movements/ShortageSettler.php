<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Movements;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMove;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * Allocates compatible inbound quantity against open negative-stock
 * shortages (oldest exact-identity first) and appends the immutable
 * settlement facts and provisional-to-actual valuation repairs. Internal to
 * PostInventoryMovement — never call this from outside that Action.
 */
final class ShortageSettler
{
    /** @return Collection<int, array{shortage:NegativeStockShortage, qty:BigDecimal}> */
    public function allocate(Item $item, Location $location, ?StockBatch $batch, BigDecimal $inboundQty): Collection
    {
        $shortages = NegativeStockShortage::query()
            ->where('item_id', $item->id)->where('location_id', $location->id)
            ->when($batch === null, fn ($query) => $query->whereNull('batch_id'), fn ($query) => $query->where('batch_id', $batch->id))
            ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED', 'OPEN_CUTOVER_SHORTAGE'])
            ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        $remaining = $inboundQty;
        $allocations = collect();
        foreach ($shortages as $shortage) {
            if ($remaining->isZero()) {
                break;
            }
            $openQty = BigDecimal::of($shortage->qty_open);
            $settledQty = $openQty->isLessThan($remaining) ? $openQty : $remaining;
            $allocations->push(['shortage' => $shortage, 'qty' => $settledQty]);
            $remaining = $remaining->minus($settledQty);
        }

        /** @var Collection<int, array{shortage:NegativeStockShortage, qty:BigDecimal}> $allocations */
        return $allocations;
    }

    /**
     * Settles a receipt's allocations at the receipt's own actual base cost.
     *
     * @param  Collection<int, array{shortage:NegativeStockShortage, qty:BigDecimal}>  $settlements
     */
    public function applyReceiptSettlements(StockMove $move, Collection $settlements, BigDecimal $unitCost, BigDecimal $baseUnitCost, string $fxRate, ?AvcoPool $pool, int $accountId): void
    {
        foreach ($settlements as $settlement) {
            $shortage = $settlement['shortage'];
            $settlementQty = $settlement['qty'];
            $provisionalBaseCost = BigDecimal::of($shortage->provisional_unit_cost);
            $baseDifference = $baseUnitCost->minus($provisionalBaseCost);
            $baseRepair = $provisionalBaseCost->minus($baseUnitCost)->multipliedBy($settlementQty)->toScale(4, RoundingMode::HALF_UP);
            $provisionalTransactionCost = $provisionalBaseCost->dividedBy(BigDecimal::of($fxRate), 4, RoundingMode::HALF_UP);
            $transactionDifference = $unitCost->minus($provisionalTransactionCost);
            $transactionRepair = $provisionalTransactionCost->minus($unitCost)->multipliedBy($settlementQty)->toScale(4, RoundingMode::HALF_UP);
            $move->valuationEntries()->create([
                'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'VALUATION_ADJUSTMENT',
                'qty' => '0.0000', 'unit_cost' => (string) $transactionDifference->abs(),
                'amount' => (string) $transactionRepair, 'base_amount' => (string) $baseRepair,
                'account_id' => $accountId,
            ]);
            $this->closeAllocation($move, $shortage, $settlementQty, $provisionalBaseCost, $baseUnitCost, $baseRepair);
        }
    }

    /**
     * Settles a transfer's allocations against the actual cost of the exact
     * incoming stock consumed — per cost slice, so a multi-cost FIFO transfer
     * repairs each settled layer at that layer's own cost rather than a
     * weighted average that matches no consumed layer.
     *
     * @param  Collection<int, array{shortage:NegativeStockShortage, qty:BigDecimal}>  $settlements
     * @param  Collection<int, array{qty:BigDecimal, cost:BigDecimal}>  $slices  in incoming-consumption order
     * @return BigDecimal the total base-value repair, for the destination pool write
     */
    public function applyTransferSettlements(StockMove $move, Collection $settlements, Collection $slices, ?AvcoPool $pool, int $accountId): BigDecimal
    {
        $queue = $slices->values()->all();
        $index = 0;
        $totalRepair = BigDecimal::zero();
        foreach ($settlements as $settlement) {
            $shortage = $settlement['shortage'];
            $remaining = $settlement['qty'];
            $provisional = BigDecimal::of($shortage->provisional_unit_cost);
            $repair = BigDecimal::zero();
            $costed = BigDecimal::zero();
            while ($remaining->isGreaterThan(0)) {
                if (! isset($queue[$index])) {
                    throw new DomainInvariantException('Transfer settlement exceeds the incoming quantity.', 'qty');
                }
                $sliceQty = $queue[$index]['qty'];
                $take = $sliceQty->isLessThan($remaining) ? $sliceQty : $remaining;
                $repair = $repair->plus($provisional->minus($queue[$index]['cost'])->multipliedBy($take)->toScale(4, RoundingMode::HALF_UP));
                $costed = $costed->plus($queue[$index]['cost']->multipliedBy($take));
                $queue[$index]['qty'] = $sliceQty->minus($take);
                if ($queue[$index]['qty']->isZero()) {
                    $index++;
                }
                $remaining = $remaining->minus($take);
            }
            $actual = $costed->dividedBy($settlement['qty'], 4, RoundingMode::HALF_UP);
            $move->valuationEntries()->create([
                'avco_pool_id' => $pool?->id, 'pool_effect' => $pool === null ? null : 'VALUATION_ADJUSTMENT',
                'qty' => '0.0000', 'unit_cost' => (string) $actual->minus($provisional)->abs(),
                'amount' => (string) $repair, 'base_amount' => (string) $repair, 'account_id' => $accountId,
            ]);
            $this->closeAllocation($move, $shortage, $settlement['qty'], $provisional, $actual, $repair);
            $totalRepair = $totalRepair->plus($repair);
        }

        return $totalRepair;
    }

    private function closeAllocation(StockMove $move, NegativeStockShortage $shortage, BigDecimal $settledQty, BigDecimal $provisional, BigDecimal $actual, BigDecimal $repair): void
    {
        $newOpenQty = BigDecimal::of($shortage->qty_open)->minus($settledQty);
        $shortage->update([
            'qty_open' => (string) $newOpenQty,
            'status' => $newOpenQty->isZero() ? 'SETTLED' : 'PARTIALLY_SETTLED',
            'settled_at' => $newOpenQty->isZero() ? now() : null,
        ]);
        $shortage->settlements()->create([
            'inbound_move_id' => $move->id, 'qty_settled' => (string) $settledQty,
            'provisional_unit_cost' => (string) $provisional,
            'actual_unit_cost' => (string) $actual,
            'unit_cost_difference' => (string) $actual->minus($provisional),
            'valuation_adjustment' => (string) $repair, 'settled_at' => now(),
        ]);
    }
}
