<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Models\ItemSupplier;
use Illuminate\Support\Facades\DB;

final class ArchiveItemSupplier
{
    public function __invoke(ItemSupplier $itemSupplier): void
    {
        DB::transaction(fn () => $itemSupplier->update(['active' => false]));
    }
}
