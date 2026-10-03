<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Support\PackageTrackingMode;
use App\Support\CompanyContext;

/** Creation-time resolution only. Never dynamically reinterprets an existing item's policy. */
final class ResolvePackageTrackingPolicy
{
    /** @return array{mode:PackageTrackingMode,source:string,category_id:int|null} */
    public function __invoke(?PackageTrackingMode $explicit, ?int $categoryId): array
    {
        if ($explicit !== null) {
            return ['mode' => $explicit, 'source' => 'ITEM', 'category_id' => null];
        }
        $visited = [];
        while ($categoryId !== null) {
            if (isset($visited[$categoryId])) {
                throw new DomainInvariantException('Category policy ancestry contains a cycle.');
            }
            $visited[$categoryId] = true;
            $category = Category::query()->findOrFail($categoryId);
            if ($category->default_package_tracking_mode !== null) {
                return ['mode' => $category->default_package_tracking_mode, 'source' => 'CATEGORY', 'category_id' => $category->id];
            }
            $categoryId = $category->parent_id;
        }
        $company = Company::query()->findOrFail(app(CompanyContext::class)->id());

        return ['mode' => $company->default_package_tracking_mode, 'source' => 'COMPANY', 'category_id' => null];
    }
}
