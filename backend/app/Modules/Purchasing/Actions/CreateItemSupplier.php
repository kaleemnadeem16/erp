<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class CreateItemSupplier
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): ItemSupplier
    {
        ActionAuthorization::require($actor, 'purchasing.item-supplier.manage', 'create an item-supplier link');

        return DB::transaction(function () use ($attributes, $actor): ItemSupplier {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'purchasing.item-supplier.manage', 'create an item-supplier link');

            return ItemSupplier::create($attributes)->refresh();
        });
    }
}
