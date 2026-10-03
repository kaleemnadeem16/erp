<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Models\User;
use App\Modules\Estimating\Models\MaterialVarianceReason;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class CreateMaterialVarianceReason
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): MaterialVarianceReason
    {
        ActionAuthorization::require($actor, 'estimating.material_variance_policy.manage', 'create a variance reason');

        return DB::transaction(fn () => MaterialVarianceReason::create($attributes)->refresh());
    }
}
