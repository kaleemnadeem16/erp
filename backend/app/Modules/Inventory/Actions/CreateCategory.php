<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Category;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class CreateCategory
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): Category
    {
        ActionAuthorization::require($actor, 'inventory.category.manage', 'create a category');

        return DB::transaction(function () use ($attributes): Category {
            $category = Category::create($attributes);

            return $category->refresh();
        });
    }
}
