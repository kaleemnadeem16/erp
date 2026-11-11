<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Inventory\Models\Category;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Facades\DB;

final class CreateCategory
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): Category
    {
        ActionAuthorization::require($actor, 'inventory.category.manage', 'create a category');

        AccountMappingAccess::requireWrite($actor, $attributes);

        return DB::transaction(function () use ($attributes, $actor): Category {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'inventory.category.manage', 'create a category');
            AccountMappingAccess::requireWrite($actor, $attributes);
            $category = Category::create($attributes);

            return $category->refresh();
        });
    }
}
