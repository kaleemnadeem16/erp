<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Category;
use Illuminate\Support\Facades\DB;

final class UpdateCategory
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Category $category, array $attributes): Category
    {
        return DB::transaction(function () use ($category, $attributes): Category {
            $category->update($attributes);

            return $category->refresh();
        });
    }
}
