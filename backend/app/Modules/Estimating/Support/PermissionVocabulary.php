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
            'estimating.rate-dimension.manage',
            'estimating.application-rate.manage',
            'estimating.formulation.manage',
            'estimating.assembly-group.manage',
            'estimating.assembly.manage',
            'estimating.resource-rate.manage',
            'estimating.overhead-policy.manage',
            'estimating.material_variance_policy.manage',
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
