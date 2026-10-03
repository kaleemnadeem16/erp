<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Category;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class ArchiveCategory
{
    public function __invoke(Category $category, User $actor): void
    {
        ActionAuthorization::require($actor, 'inventory.category.manage', 'archive a category');
        DB::transaction(fn () => $category->delete());
    }
}
