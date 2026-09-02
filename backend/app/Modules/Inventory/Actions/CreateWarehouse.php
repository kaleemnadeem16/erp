<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

final class CreateWarehouse
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): Warehouse
    {
        return DB::transaction(function () use ($attributes): Warehouse {
            $location = ['code' => $attributes['location_code'], 'name' => $attributes['location_name']];
            unset($attributes['location_code'], $attributes['location_name']);
            $warehouse = Warehouse::create($attributes);
            $warehouse->locations()->create($location);

            return $warehouse->refresh()->load('locations');
        });
    }
}
