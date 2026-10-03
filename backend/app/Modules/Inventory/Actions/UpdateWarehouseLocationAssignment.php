<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class UpdateWarehouseLocationAssignment
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(WarehouseLocationAssignment $assignment, array $attributes, User $actor): WarehouseLocationAssignment
    {
        ActionAuthorization::require($actor, 'inventory.location.manage', 'change a warehouse location role');

        return DB::transaction(function () use ($assignment, $attributes): WarehouseLocationAssignment {
            $assignment->update($attributes);

            return $assignment->refresh();
        });
    }
}
