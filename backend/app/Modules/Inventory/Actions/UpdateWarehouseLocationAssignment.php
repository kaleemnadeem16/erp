<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use Illuminate\Support\Facades\DB;

final class UpdateWarehouseLocationAssignment
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(WarehouseLocationAssignment $assignment, array $attributes): WarehouseLocationAssignment
    {
        return DB::transaction(function () use ($assignment, $attributes): WarehouseLocationAssignment {
            $assignment->update($attributes);

            return $assignment->refresh();
        });
    }
}
