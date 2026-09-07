<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Movements;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves, locks and writes AVCO pool state for PostInventoryMovement and,
 * for the recalculation workflow's own execution Actions
 * (ExecuteRecalculation, OutboundRecalculationExecutor), for converging live
 * pool state to an approved counterfactual. All multi-pool locking here is
 * in ascending pool-id order so concurrent movements over the same pools
 * cannot invert lock order and deadlock.
 */
final class AvcoPoolManager
{
    public function lock(Item $item, Location $location, ?StockBatch $batch): AvcoPool
    {
        $identity = $this->identity($item, $location, $batch);
        $this->ensureExists($identity);

        return AvcoPool::query()->where($identity)->lockForUpdate()->firstOrFail();
    }

    /**
     * Locks the source and destination pools of a transfer in ascending id
     * order. Returns the same model instance twice for a same-pool transfer.
     *
     * @return array{0: AvcoPool, 1: AvcoPool} source pool, destination pool
     */
    public function lockPair(Item $item, Location $source, Location $destination, ?StockBatch $batch): array
    {
        $sourceIdentity = $this->identity($item, $source, $batch);
        $destinationIdentity = $this->identity($item, $destination, $batch);
        $this->ensureExists($sourceIdentity);
        $this->ensureExists($destinationIdentity);
        $sourceId = (int) AvcoPool::query()->where($sourceIdentity)->value('id');
        $destinationId = (int) AvcoPool::query()->where($destinationIdentity)->value('id');
        $pools = AvcoPool::query()->whereIn('id', [$sourceId, $destinationId])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $source = $pools->get($sourceId);
        $destination = $pools->get($destinationId);
        if ($source === null || $destination === null) {
            throw new DomainInvariantException('The resolved AVCO pool is missing.', 'item_id');
        }

        return [$source, $destination];
    }

    /**
     * Resolves and locks every pool an outbound recalculation's connected
     * component actually touched, keyed by the same scope-key strings
     * MovementReplayHistory::key() and movement_impact's counterfactual_scopes
     * already use ('company' | 'warehouse:ID' | 'batch:ID') — ascending
     * pool-id lock order, the same discipline lock()/lockPair() use, just
     * generalised to however many pools a connected component contains
     * instead of exactly one or two.
     *
     * @param  Collection<int, string>  $scopeKeys
     * @return Collection<string, AvcoPool> keyed by scope key
     */
    public function lockByScopeKeys(Item $item, Collection $scopeKeys): Collection
    {
        $identityByKey = $scopeKeys->mapWithKeys(fn (string $key): array => [$key => $this->identityForScopeKey($item, $key)]);
        foreach ($identityByKey as $identity) {
            $this->ensureExists($identity);
        }
        $poolIdByKey = $identityByKey->map(fn (array $identity): int => (int) AvcoPool::query()->where($identity)->value('id'));
        $pools = AvcoPool::query()->whereIn('id', $poolIdByKey->unique()->values())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return $poolIdByKey->map(fn (int $id): AvcoPool => $pools->get($id));
    }

    /** @return array{item_id:int, scope:string, warehouse_id:int|null, batch_id:int|null} */
    private function identityForScopeKey(Item $item, string $key): array
    {
        $base = ['item_id' => $item->id, 'scope' => $item->avco_pool_scope->value, 'warehouse_id' => null, 'batch_id' => null];

        return match (true) {
            $key === 'company' => $base,
            str_starts_with($key, 'warehouse:') => [...$base, 'warehouse_id' => (int) substr($key, strlen('warehouse:'))],
            str_starts_with($key, 'batch:') => [...$base, 'batch_id' => (int) substr($key, strlen('batch:'))],
            default => throw new DomainInvariantException('Unrecognised AVCO scope key.', 'item_id'),
        };
    }

    /**
     * Writes recomputed pool state after a quantity/value change. A positive
     * pool re-derives its average and refreshes last_valid; a non-positive
     * pool keeps its frozen last-valid rate — that rate's whole purpose is
     * surviving zero/negative periods for later provisional costing.
     */
    public function write(AvcoPool $pool, BigDecimal $qty, BigDecimal $value): void
    {
        $value = $value->toScale(4, RoundingMode::HALF_UP);
        if ($qty->isZero() && $value->isZero()) {
            // A clean full drawdown to zero is an ordinary empty pool, not an
            // ADR 0037 excursion — never freeze, never lose last_valid.
            $pool->update([
                'qty_on_hand' => '0.0000', 'inventory_value' => '0.0000',
                'avg_unit_cost' => '0.0000', 'base_avg_unit_cost' => '0.0000',
                'status' => 'ACTIVE',
            ]);

            return;
        }
        $positive = $qty->isGreaterThan(0);
        $average = $positive ? $value->dividedBy($qty, 4, RoundingMode::HALF_UP) : BigDecimal::of($pool->last_valid_avg_unit_cost);
        $pool->update([
            'qty_on_hand' => (string) $qty, 'inventory_value' => (string) $value,
            'avg_unit_cost' => (string) $average, 'base_avg_unit_cost' => (string) $average,
            'last_valid_avg_unit_cost' => $positive ? (string) $average : $pool->last_valid_avg_unit_cost,
            'status' => $positive ? 'ACTIVE' : 'FROZEN',
        ]);
    }

    /**
     * Read-only pool lookup for preview/what-if computation — no lock, no
     * insertOrIgnore. Returns null when the pool has never been touched by a
     * live movement (nothing to preview against).
     */
    public function resolveExisting(Item $item, Location $location, ?StockBatch $batch): ?AvcoPool
    {
        return AvcoPool::query()->where($this->identity($item, $location, $batch))->first();
    }

    /** @return array{item_id:int, scope:string, warehouse_id:int|null, batch_id:int|null} */
    private function identity(Item $item, Location $location, ?StockBatch $batch): array
    {
        $scope = $item->avco_pool_scope;
        if ($scope === AvcoPoolScope::CompanyItemBatch && $batch === null) {
            throw new DomainInvariantException('Batch-valued AVCO requires a captured batch identity.', 'batch_no');
        }

        return [
            'item_id' => $item->id, 'scope' => $scope->value,
            'warehouse_id' => $scope === AvcoPoolScope::WarehouseItem ? $location->warehouse_id : null,
            'batch_id' => $scope === AvcoPoolScope::CompanyItemBatch ? $batch->id : null,
        ];
    }

    /** @param array{item_id:int, scope:string, warehouse_id:int|null, batch_id:int|null} $identity */
    private function ensureExists(array $identity): void
    {
        DB::table('avco_pools')->insertOrIgnore(array_merge($identity, [
            'company_id' => app(CompanyContext::class)->id(), 'qty_on_hand' => 0, 'inventory_value' => 0,
            'avg_unit_cost' => 0, 'base_avg_unit_cost' => 0, 'last_valid_avg_unit_cost' => 0,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }
}
