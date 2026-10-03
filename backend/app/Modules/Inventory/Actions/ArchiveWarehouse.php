<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Warehouse;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class ArchiveWarehouse
{
    public function __invoke(Warehouse $warehouse, User $actor): void
    {
        ActionAuthorization::require($actor, 'inventory.warehouse.manage', 'archive a warehouse');
        DB::transaction(function () use ($warehouse): void {
            $warehouse->update(['active' => false, 'is_default' => false]);
            $warehouse->locations()->update(['active' => false]);
        });
    }
}
