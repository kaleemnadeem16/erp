<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Category;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class UpdateCategory
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Category $category, array $attributes, User $actor): Category
    {
        ActionAuthorization::require($actor, 'inventory.category.manage', 'edit a category');

        return DB::transaction(function () use ($category, $attributes): Category {
            $category->update($attributes);

            return $category->refresh();
        });
    }
}
