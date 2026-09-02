<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Models\ItemSupplier;
use Illuminate\Support\Facades\DB;

final class CreateItemSupplier
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): ItemSupplier
    {
        return DB::transaction(fn () => ItemSupplier::create($attributes)->refresh());
    }
}
