<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Support\MaterialVariancePolicyResolution;
use App\Modules\Estimating\Support\MaterialVariancePolicyStatus;
use App\Modules\Estimating\Support\VarianceResolutionMethod;
use App\Modules\Estimating\Support\VarianceScopeType;
use App\Modules\Inventory\Models\Item;

/**
 * ADR 0054's hierarchy: the item's own ACTIVE policy, else its nearest item-category ancestor's,
 * else the company default. Like overhead, "none" is a legitimate terminal state, not an error —
 * the caller then applies ADR 0054's built-in default (any non-zero variance needs a reason).
 */
final class ResolveMaterialVariancePolicy
{
    public function __invoke(Item $item): MaterialVariancePolicyResolution
    {
        $own = $this->active(VarianceScopeType::Item, $item->id);
        if ($own !== null) {
            return new MaterialVariancePolicyResolution($own, VarianceResolutionMethod::Item);
        }

        $visited = [];
        for ($category = $item->category; $category !== null && ! in_array($category->id, $visited, true); $category = $category->parent) {
            $visited[] = $category->id;
            $policy = $this->active(VarianceScopeType::Category, $category->id);
            if ($policy !== null) {
                return new MaterialVariancePolicyResolution($policy, VarianceResolutionMethod::Category);
            }
        }

        $company = $this->active(VarianceScopeType::Company, null);

        return $company !== null
            ? new MaterialVariancePolicyResolution($company, VarianceResolutionMethod::Company)
            : new MaterialVariancePolicyResolution(null, VarianceResolutionMethod::None);
    }

    private function active(VarianceScopeType $scopeType, ?int $scopeId): ?MaterialVariancePolicy
    {
        return MaterialVariancePolicy::query()
            ->forScope($scopeType, $scopeId)
            ->where('status', MaterialVariancePolicyStatus::Active)
            ->with('bands.uom')
            ->first();
    }
}
