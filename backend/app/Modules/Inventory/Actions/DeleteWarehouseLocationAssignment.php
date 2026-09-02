<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use Illuminate\Support\Facades\DB;

final class DeleteWarehouseLocationAssignment
{
    public function __invoke(WarehouseLocationAssignment $assignment): void
    {
        DB::transaction(fn () => $assignment->delete());
    }
}
