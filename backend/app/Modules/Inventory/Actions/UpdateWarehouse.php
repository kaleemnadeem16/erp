<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class UpdateWarehouse
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Warehouse $warehouse, array $attributes, User $actor): Warehouse
    {
        ActionAuthorization::require($actor, 'inventory.warehouse.manage', 'edit a warehouse');

        return DB::transaction(function () use ($warehouse, $attributes, $actor): Warehouse {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.warehouse.manage', 'edit a warehouse');
            if (($attributes['active'] ?? false) === true && ! $warehouse->active
                && ! $warehouse->locations()->where('active', true)->exists()) {
                throw new DomainInvariantException(
                    'A warehouse cannot be reactivated without at least one active location.',
                    'active',
                );
            }

            $warehouse->update($attributes);

            return $warehouse->refresh();
        });
    }
}
