<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class ArchiveItem
{
    public function __invoke(Item $item, User $actor): void
    {
        ActionAuthorization::require($actor, 'inventory.item.manage', 'archive an item');
        DB::transaction(function () use ($item, $actor): void {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.item.manage', 'archive an item');
            $item->delete();
        });
    }
}
