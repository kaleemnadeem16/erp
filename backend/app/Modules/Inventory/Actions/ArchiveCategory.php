<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Category;
use Illuminate\Support\Facades\DB;

final class ArchiveCategory
{
    public function __invoke(Category $category): void
    {
        DB::transaction(fn () => $category->delete());
    }
}
