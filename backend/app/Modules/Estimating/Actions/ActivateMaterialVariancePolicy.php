<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Support\MaterialVariancePolicyStatus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Makes a DRAFT policy the live rule for its scope, superseding whatever was ACTIVE there and
 * closing its period the moment this one opens (same as ActivateOverheadPolicy). The superseding
 * runs first in the same transaction so the one-active index never sees two ACTIVE rows.
 */
final class ActivateMaterialVariancePolicy
{
    public function __invoke(MaterialVariancePolicy $policy): MaterialVariancePolicy
    {
        if ($policy->status !== MaterialVariancePolicyStatus::Draft) {
            throw new DomainInvariantException('Only a DRAFT variance policy can be activated.', 'status');
        }

        return DB::transaction(function () use ($policy): MaterialVariancePolicy {
            $today = Date::today()->toDateString();

            MaterialVariancePolicy::query()
                ->forScope($policy->scope_type, $policy->scope_id)
                ->where('status', MaterialVariancePolicyStatus::Active)
                ->update(['status' => MaterialVariancePolicyStatus::Superseded, 'effective_to' => $today]);

            $policy->update(['status' => MaterialVariancePolicyStatus::Active, 'effective_from' => $today, 'effective_to' => null]);

            return $policy->refresh()->load('bands.uom');
        });
    }
}
