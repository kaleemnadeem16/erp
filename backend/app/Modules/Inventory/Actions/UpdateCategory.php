<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Inventory\Models\Category;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class UpdateCategory
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Category $category, array $attributes, User $actor): Category
    {
        ActionAuthorization::require($actor, 'inventory.category.manage', 'edit a category');

        AccountMappingAccess::requireWrite($actor, $attributes);

        return DB::transaction(function () use ($category, $attributes, $actor): Category {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.category.manage', 'edit a category');
            AccountMappingAccess::requireWrite($actor, $attributes);
            $category->update($attributes);

            return $category->refresh();
        });
    }
}
