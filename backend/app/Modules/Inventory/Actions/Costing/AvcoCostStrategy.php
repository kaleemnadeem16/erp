<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Costing;

use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\CostResolutionFactory;
use App\Modules\Inventory\Support\Costing\CostStrategy;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use Brick\Math\BigDecimal;

final class AvcoCostStrategy implements CostStrategy
{
    public function __construct(private readonly CostResolutionFactory $results) {}

    public function method(): string
    {
        return 'AVCO';
    }

    public function resolve(MaterialCostRequest $request, CostReadSession $session): CostResolution
    {
        $scope = $request->item->avco_pool_scope;
        $warehouseId = $request->warehouseId ?? ($request->locationId === null ? null : Location::query()->findOrFail($request->locationId)->warehouse_id);
        if (($scope === AvcoPoolScope::WarehouseItem && $warehouseId === null)
            || ($scope === AvcoPoolScope::CompanyItemBatch && $request->batchId === null)) {
            return $this->results->unknown($request, $session, 'SCOPE_NOT_COSTABLE');
        }
        $pool = AvcoPool::query()->where('item_id', $request->item->id)->where('scope', $scope)
            ->when($scope === AvcoPoolScope::WarehouseItem, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($scope === AvcoPoolScope::CompanyItemBatch, fn ($q) => $q->where('batch_id', $request->batchId))->first();
        if ($pool === null) {
            return $this->results->unknown($request, $session, 'NO_COST_EVIDENCE');
        }
        if ($pool->status !== AvcoPoolStatus::Active) {
            return $this->results->unknown($request, $session, 'POOL_FROZEN');
        }
        if (! BigDecimal::of($pool->qty_on_hand)->isPositive() || BigDecimal::of($pool->base_avg_unit_cost)->isNegative()) {
            return $this->results->unknown($request, $session, 'POOL_NOT_COSTABLE');
        }
        $used = ['type' => $scope === AvcoPoolScope::CompanyItem ? 'COMPANY' : ($scope === AvcoPoolScope::WarehouseItem ? 'WAREHOUSE' : 'CHOSEN_STOCK'),
            'warehouse_id' => $scope === AvcoPoolScope::WarehouseItem ? $warehouseId : null,
            'location_id' => null, 'batch_id' => $scope === AvcoPoolScope::CompanyItemBatch ? $request->batchId : null, 'serial_no' => null];

        return $this->results->make($request, $session, $request->quantity,
            $request->quantity->multipliedBy($pool->base_avg_unit_cost),
            [['source_type' => 'AVCO_POOL', 'pool_id' => $pool->id, 'scope' => $scope->value,
                'qty_on_hand' => $pool->qty_on_hand, 'inventory_value' => $pool->inventory_value,
                'base_unit_cost' => $pool->base_avg_unit_cost, 'status' => $pool->status->value]], scopeUsed: $used);
    }
}
