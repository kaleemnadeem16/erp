<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\ItemQuantityUnit;
use Illuminate\Support\Facades\DB;

final class CreateItemQuantityUnit
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): ItemQuantityUnit
    {
        return DB::transaction(fn () => ItemQuantityUnit::create($attributes)->refresh());
    }
}
