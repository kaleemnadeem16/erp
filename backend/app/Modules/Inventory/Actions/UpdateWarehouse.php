<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

final class UpdateWarehouse
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Warehouse $warehouse, array $attributes): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $attributes): Warehouse {
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
