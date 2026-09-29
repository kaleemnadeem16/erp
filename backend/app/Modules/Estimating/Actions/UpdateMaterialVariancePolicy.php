<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Support\MaterialVariancePolicyStatus;
use App\Modules\Estimating\Support\VariancePolicyGuard;
use Illuminate\Support\Facades\DB;

/**
 * Only a DRAFT policy may be edited; once ACTIVE or SUPERSEDED it is frozen history. When `bands` is
 * given it replaces the whole set (the draft is one document, so there is no half-edited band list).
 * The scope is not editable: a policy does not change what it governs — create another.
 */
final class UpdateMaterialVariancePolicy
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(MaterialVariancePolicy $policy, array $attributes): MaterialVariancePolicy
    {
        if ($policy->status !== MaterialVariancePolicyStatus::Draft) {
            throw new DomainInvariantException('Only a DRAFT variance policy may be edited; create a new version instead.', 'status');
        }

        $replaceBands = array_key_exists('bands', $attributes);
        /** @var list<array<string, mixed>> $bands */
        $bands = $attributes['bands'] ?? [];
        unset($attributes['bands']);
        VariancePolicyGuard::assertWritable($bands, $attributes['minimum_effect'] ?? null);

        return DB::transaction(function () use ($policy, $attributes, $replaceBands, $bands): MaterialVariancePolicy {
            $policy->update($attributes);
            if ($replaceBands) {
                $policy->bands()->delete();
                foreach ($bands as $band) {
                    $policy->bands()->create($band);
                }
            }

            return $policy->refresh()->load('bands.uom');
        });
    }
}
