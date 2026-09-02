<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Models\ItemSupplier;
use Illuminate\Support\Facades\DB;

final class UpdateItemSupplier
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(ItemSupplier $itemSupplier, array $attributes): ItemSupplier
    {
        return DB::transaction(function () use ($itemSupplier, $attributes): ItemSupplier {
            $itemSupplier->update($attributes);

            return $itemSupplier->refresh();
        });
    }
}
