<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use Illuminate\Support\Facades\DB;

final class CreateItemQuantityUnit
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): ItemQuantityUnit
    {
        if (! $actor->can('inventory.item.manage')) {
            throw new DomainInvariantException('The actor cannot maintain item conversions.');
        }

        return DB::transaction(fn () => ItemQuantityUnit::create($attributes)->refresh());
    }
}
