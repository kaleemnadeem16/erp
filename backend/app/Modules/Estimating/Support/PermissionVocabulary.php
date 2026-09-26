<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** Estimating-owned capabilities registered through the shared permission registry. */
final class PermissionVocabulary
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'estimating.overhead-policy.manage',
            'estimating.policy-history.view',
            'estimating.material_preview.run',
            'estimating.inventory_availability.view',
            'estimating.internal_cost.view',
            'estimating.material_plan.adjust',
            'estimating.package_plan.adopt',
            'estimating.material_variance.request',
            'estimating.material_variance.approve',
            'estimating.material_resolution_history.view',
        ];
    }
}
