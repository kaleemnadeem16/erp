<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Models\User;

/**
 * Who may read a recipe: a formulation's input items, quantities and wastage, an application rate's material per unit of work, and an
 * assembly's component quantities. Cost, rate and margin fields have their own capabilities; this one protects the composition itself,
 * which for a manufacturer can be the most sensitive thing in the system (audit A9, ADR 0086).
 *
 * `estimating.recipe.view` is the capability an administrator grants to anyone who needs to read recipes without maintaining them. The
 * others are the duties that cannot work without reading them: maintaining formulations, application rates or assemblies, running the
 * Material Preview (whose result is the expanded recipe for a quantity of work), and reading internal costs, because a cost breakdown
 * lists every component and quantity and cannot be shown without them.
 */
final class RecipeReadAccess
{
    public static function allows(?User $user): bool
    {
        return $user?->hasAnyPermission([
            'estimating.recipe.view',
            'estimating.formulation.manage',
            'estimating.application-rate.manage',
            'estimating.assembly.manage',
            'estimating.material_preview.run',
            'estimating.internal_cost.view',
        ]) === true;
    }
}
