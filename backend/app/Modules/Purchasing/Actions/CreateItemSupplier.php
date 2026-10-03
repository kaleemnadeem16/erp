<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class CreateItemSupplier
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): ItemSupplier
    {
        ActionAuthorization::require($actor, 'purchasing.item-supplier.manage', 'create an item-supplier link');

        return DB::transaction(fn () => ItemSupplier::create($attributes)->refresh());
    }
}
