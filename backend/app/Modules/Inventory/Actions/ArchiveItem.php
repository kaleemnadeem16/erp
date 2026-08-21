<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Facades\DB;

final class ArchiveItem
{
    public function __invoke(Item $item): void
    {
        DB::transaction(fn () => $item->delete());
    }
}
