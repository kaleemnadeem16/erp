<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

final class CreateLocation
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): Location
    {
        return DB::transaction(function () use ($attributes): Location {
            $warehouse = Warehouse::query()->findOrFail($attributes['warehouse_id']);
            if (! $warehouse->active) {
                throw new DomainInvariantException('A location cannot be added to an inactive warehouse.', 'warehouse_id');
            }

            return Location::create($attributes)->refresh();
        });
    }
}
