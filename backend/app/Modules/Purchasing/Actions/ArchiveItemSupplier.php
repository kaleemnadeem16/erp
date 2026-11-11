<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class ArchiveItemSupplier
{
    public function __invoke(ItemSupplier $itemSupplier, User $actor): void
    {
        ActionAuthorization::require($actor, 'purchasing.item-supplier.manage', 'archive an item-supplier link');
        DB::transaction(function () use ($itemSupplier, $actor): void {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'purchasing.item-supplier.manage', 'archive an item-supplier link');
            $itemSupplier->update(['active' => false]);
        });
    }
}
