<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\PhysicalCount;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\PhysicalCountStatus;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class CreatePhysicalCount
{
    /** @param array{warehouse_id:int, reference?:string|null} $input */
    public function __invoke(array $input, User $actor): PhysicalCount
    {
        if (! $actor->can('inventory.physical_count.create')) {
            throw new DomainInvariantException('The actor is not permitted to create a physical count.', 'created_by');
        }
        WarehouseScope::requireWarehouse($actor, 'inventory.physical_count.create', (int) $input['warehouse_id'], 'create a physical count');

        return DB::transaction(function () use ($input, $actor): PhysicalCount {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.physical_count.create', 'create a physical count', 'created_by');
            WarehouseScope::requireWarehouse($actor, 'inventory.physical_count.create', (int) $input['warehouse_id'], 'create a physical count');
            $warehouse = Warehouse::query()->findOrFail($input['warehouse_id']);
            if (! $warehouse->active) {
                throw new DomainInvariantException('A physical count requires an active warehouse.', 'warehouse_id');
            }

            return PhysicalCount::query()->create([
                'warehouse_id' => $warehouse->id, 'reference' => $input['reference'] ?? null,
                'status' => PhysicalCountStatus::Draft,
                'created_by' => $actor->id, 'created_at' => now(),
            ])->refresh();
        });
    }
}
