<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class ArchiveItemSupplier
{
    public function __invoke(ItemSupplier $itemSupplier, User $actor): void
    {
        ActionAuthorization::require($actor, 'purchasing.item-supplier.manage', 'archive an item-supplier link');
        DB::transaction(fn () => $itemSupplier->update(['active' => false]));
    }
}
