<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class ArchiveItem
{
    public function __invoke(Item $item, User $actor): void
    {
        ActionAuthorization::require($actor, 'inventory.item.manage', 'archive an item');
        DB::transaction(fn () => $item->delete());
    }
}
