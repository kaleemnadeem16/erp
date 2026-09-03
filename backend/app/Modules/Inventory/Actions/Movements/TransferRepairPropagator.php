<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Movements;

use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\StockTransferDetail;
use App\Modules\Inventory\Models\StockTransferRepair;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A pool that just recovered from non-positive may have carried its stale
 * frozen rate onto other pools via provisional cross-pool transfers taken
 * while it was frozen. Each such transfer is corrected exactly once, here,
 * to the now-known true rate — moving the identical base amount out of its
 * source and into its destination so company value stays conserved. If that
 * destination itself relayed the value onward through its own provisional
 * transfer before this repair arrived, the correction cascades to it too.
 *
 * Internal to PostInventoryMovement — never call this from outside that
 * Action.
 */
final class TransferRepairPropagator
{
    public function __construct(private readonly AvcoPoolManager $pools) {}

    public function propagate(int $rootPoolId, BigDecimal $actualRate, int $accountId): void
    {
        $frontier = [$rootPoolId];
        while ($frontier !== []) {
            $poolId = array_shift($frontier);
            $details = StockTransferDetail::query()
                ->where('source_avco_pool_id', $poolId)
                ->where('destination_avco_pool_id', '!=', $poolId)
                ->where('is_provisional', true)
                ->whereDoesntHave('repair')
                ->with('stockMove')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($details as $detail) {
                $move = $detail->stockMove;
                $qty = BigDecimal::of($move->qty);
                $frozenRate = BigDecimal::of($move->unit_cost);
                $delta = $qty->multipliedBy($actualRate->minus($frozenRate))->toScale(4, RoundingMode::HALF_UP);
                // Both pools locked in ascending id order — the same discipline
                // as AvcoPoolManager::lockPair() — so a concurrent transfer or
                // propagation over the same pools cannot deadlock against this.
                $pair = AvcoPool::query()
                    ->whereIn('id', [$detail->source_avco_pool_id, $detail->destination_avco_pool_id])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $source = $pair->get($detail->source_avco_pool_id) ?? throw new \RuntimeException('Transfer repair source pool is missing.');
                $destination = $pair->get($detail->destination_avco_pool_id) ?? throw new \RuntimeException('Transfer repair destination pool is missing.');
                $this->pools->write($source, BigDecimal::of($source->qty_on_hand), BigDecimal::of($source->inventory_value)->minus($delta));
                $this->pools->write($destination, BigDecimal::of($destination->qty_on_hand), BigDecimal::of($destination->inventory_value)->plus($delta));
                foreach ([[$source, $delta->negated()], [$destination, $delta]] as [$pool, $amount]) {
                    $move->valuationEntries()->create([
                        'avco_pool_id' => $pool->id, 'pool_effect' => 'VALUATION_ADJUSTMENT', 'qty' => '0.0000',
                        'unit_cost' => (string) $actualRate->minus($frozenRate)->abs(), 'amount' => (string) $amount,
                        'base_amount' => (string) $amount, 'account_id' => $accountId,
                    ]);
                }
                StockTransferRepair::query()->create([
                    'stock_transfer_detail_id' => $detail->id, 'qty' => (string) $qty,
                    'provisional_unit_cost' => (string) $frozenRate, 'actual_unit_cost' => (string) $actualRate,
                    'unit_cost_difference' => (string) $actualRate->minus($frozenRate),
                    'valuation_adjustment' => (string) $delta, 'repaired_at' => now(),
                ]);
                $frontier[] = $detail->destination_avco_pool_id;
            }
        }
    }
}
