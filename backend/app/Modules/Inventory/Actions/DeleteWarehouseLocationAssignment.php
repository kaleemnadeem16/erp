<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class DeleteWarehouseLocationAssignment
{
    public function __invoke(WarehouseLocationAssignment $assignment, User $actor): void
    {
        ActionAuthorization::require($actor, 'inventory.location.manage', 'remove a warehouse location role');
        DB::transaction(function () use ($assignment, $actor): void {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            DecisionActor::require($actor, 'inventory.location.manage', 'remove a warehouse location role');
            $assignment->delete();
        });
    }
}
