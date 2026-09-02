<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

final class ArchiveWarehouse
{
    public function __invoke(Warehouse $warehouse): void
    {
        DB::transaction(function () use ($warehouse): void {
            $warehouse->update(['active' => false, 'is_default' => false]);
            $warehouse->locations()->update(['active' => false]);
        });
    }
}
