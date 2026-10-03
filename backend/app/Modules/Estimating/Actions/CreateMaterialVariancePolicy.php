<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Models\User;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Support\VariancePolicyGuard;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

/**
 * Creates a DRAFT policy version with its bands. Every version is a governed change, so `reason` and
 * `changed_by` are required on every row (same as overhead policies).
 */
final class CreateMaterialVariancePolicy
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): MaterialVariancePolicy
    {
        ActionAuthorization::require($actor, 'estimating.material_variance_policy.manage', 'create a material variance policy');
        $actorId = $actor->id;
        /** @var list<array<string, mixed>> $bands */
        $bands = $attributes['bands'] ?? [];
        unset($attributes['bands']);
        VariancePolicyGuard::assertWritable($bands, $attributes['minimum_effect'] ?? null);

        return DB::transaction(function () use ($attributes, $bands, $actorId): MaterialVariancePolicy {
            $policy = MaterialVariancePolicy::create([...$attributes, 'changed_by' => $actorId]);
            foreach ($bands as $band) {
                $policy->bands()->create($band);
            }

            return $policy->refresh()->load('bands.uom');
        });
    }
}
