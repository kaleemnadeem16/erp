<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\CostResolutionFactory;
use App\Modules\Inventory\Support\Costing\CostStrategyRegistry;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Internal Inventory read port, not a disclosure boundary. Only guarded business Actions may
 * expose its evidence. One invocation represents one hypothetical scenario, never a reservation.
 */
final class QueryMaterialCost
{
    public function __construct(
        private readonly CostStrategyRegistry $strategies,
        private readonly CostResolutionFactory $results,
        private readonly CompanyContext $company,
    ) {}

    public function __invoke(MaterialCostRequest $request): CostResolution
    {
        return $this->scenario(fn (CostReadSession $session): CostResolution => $this->resolve($request, $session));
    }

    /** @template T
     * @param  callable(CostReadSession): T  $read
     * @return T
     */
    public function scenario(callable $read): mixed
    {
        // Inside an existing transaction the owning Action supplies its consistent observation.
        if (DB::transactionLevel() > 0) {
            return $read(new CostReadSession(CarbonImmutable::now()));
        }

        return DB::transaction(function () use ($read) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $read(new CostReadSession(CarbonImmutable::now()));
        });
    }

    public function resolve(MaterialCostRequest $request, CostReadSession $session): CostResolution
    {
        $this->assertContext($request);
        if ($request->asOf !== null && ! $request->asOf->equalTo($session->observedAt)) {
            return $this->results->unknown($request, $session, 'AS_OF_NOT_SUPPORTED');
        }
        if ($request->quantity->isZero()) {
            return $this->results->make($request, $session, BigDecimal::zero(), BigDecimal::zero());
        }
        $strategy = $this->strategies->find($request->item->valuation_method->value);

        return $strategy === null
            ? $this->results->unknown($request, $session, 'METHOD_NOT_SUPPORTED')
            : $strategy->resolve($request, $session);
    }

    private function assertContext(MaterialCostRequest $request): void
    {
        if ($request->item->company_id !== $this->company->id()) {
            throw new DomainInvariantException('The material is outside the active company.', 'item_id');
        }
        if ($request->warehouseId !== null) {
            Warehouse::query()->findOrFail($request->warehouseId);
        }
        if ($request->locationId !== null) {
            $location = Location::query()->findOrFail($request->locationId);
            if ($request->warehouseId !== null && $location->warehouse_id !== $request->warehouseId) {
                throw new DomainInvariantException('Chosen location is outside the requested warehouse.', 'location_id');
            }
        }
        if ($request->batchId !== null && StockBatch::query()->findOrFail($request->batchId)->item_id !== $request->item->id) {
            throw new DomainInvariantException('Chosen batch belongs to another material.', 'batch_id');
        }
    }
}
