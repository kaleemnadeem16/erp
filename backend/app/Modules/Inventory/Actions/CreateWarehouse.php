<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class CreateWarehouse
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): Warehouse
    {
        ActionAuthorization::require($actor, 'inventory.warehouse.manage', 'create a warehouse');

        return DB::transaction(function () use ($attributes, $actor): Warehouse {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.warehouse.manage', 'create a warehouse');
            $location = ['code' => $attributes['location_code'], 'name' => $attributes['location_name']];
            unset($attributes['location_code'], $attributes['location_name']);
            $warehouse = Warehouse::create($attributes);
            $warehouse->locations()->create($location);

            return $warehouse->refresh()->load('locations');
        });
    }
}
