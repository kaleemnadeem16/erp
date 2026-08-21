<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Category;
use Illuminate\Support\Facades\DB;

final class CreateCategory
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): Category
    {
        return DB::transaction(function () use ($attributes): Category {
            $category = Category::create($attributes);

            return $category->refresh();
        });
    }
}
