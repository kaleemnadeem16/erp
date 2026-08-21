<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Facades\DB;

final class CreateItem
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): Item
    {
        return DB::transaction(function () use ($attributes): Item {
            $item = Item::create($attributes);

            return $item->refresh();
        });
    }
}
