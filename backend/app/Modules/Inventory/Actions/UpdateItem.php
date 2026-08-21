<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Facades\DB;

final class UpdateItem
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Item $item, array $attributes): Item
    {
        return DB::transaction(function () use ($item, $attributes): Item {
            $item->update($attributes);

            return $item->refresh();
        });
    }
}
