<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class UpdateItemSupplier
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(ItemSupplier $itemSupplier, array $attributes, User $actor): ItemSupplier
    {
        ActionAuthorization::require($actor, 'purchasing.item-supplier.manage', 'edit an item-supplier link');

        return DB::transaction(function () use ($itemSupplier, $attributes): ItemSupplier {
            $itemSupplier->update($attributes);

            return $itemSupplier->refresh();
        });
    }
}
