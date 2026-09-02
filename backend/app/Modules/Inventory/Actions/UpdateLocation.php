<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\Location;
use Illuminate\Support\Facades\DB;

final class UpdateLocation
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Location $location, array $attributes): Location
    {
        return DB::transaction(function () use ($location, $attributes): Location {
            if (($attributes['active'] ?? true) === false && $location->active
                && $location->warehouse->locations()->where('active', true)->count() === 1) {
                throw new DomainInvariantException('A usable warehouse must retain at least one active location.', 'active');
            }
            $location->update($attributes);

            return $location->refresh();
        });
    }
}
