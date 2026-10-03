<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class DeleteWarehouseLocationAssignment
{
    public function __invoke(WarehouseLocationAssignment $assignment, User $actor): void
    {
        ActionAuthorization::require($actor, 'inventory.location.manage', 'remove a warehouse location role');
        DB::transaction(fn () => $assignment->delete());
    }
}
