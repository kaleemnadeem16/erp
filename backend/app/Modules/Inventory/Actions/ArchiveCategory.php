<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Category;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class ArchiveCategory
{
    public function __invoke(Category $category, User $actor): void
    {
        ActionAuthorization::require($actor, 'inventory.category.manage', 'archive a category');
        DB::transaction(function () use ($category, $actor): void {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.category.manage', 'archive a category');
            $category->delete();
        });
    }
}
