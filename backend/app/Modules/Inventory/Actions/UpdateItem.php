<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Inventory\Models\Item;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class UpdateItem
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Item $item, array $attributes, User $actor): Item
    {
        ActionAuthorization::require($actor, 'inventory.item.manage', 'edit an item');

        AccountMappingAccess::requireWrite($actor, $attributes);

        return DB::transaction(function () use ($item, $attributes, $actor): Item {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.item.manage', 'edit an item');
            AccountMappingAccess::requireWrite($actor, $attributes);
            $item->update($attributes);

            return $item->refresh();
        });
    }
}
