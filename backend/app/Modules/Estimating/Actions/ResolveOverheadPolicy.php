<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Modules\Estimating\Support\OverheadPolicyResolution;
use App\Modules\Estimating\Support\OverheadPolicyStatus;
use App\Modules\Estimating\Support\OverheadResolutionMethod;
use App\Modules\Estimating\Support\OverheadScopeType;

/**
 * Implements ADR 0049's scope hierarchy: the assembly's own policy, else
 * its assembly_group's (if it has one), else the company default, else
 * none. Unlike ResolveApplicationRate/ResolveFormulation, "none" is a
 * legitimate terminal state here, not an error — see
 * docs/domain/assemblies.md "Overhead allocation" — so this returns a
 * resolution with a null policy rather than throwing.
 */
final class ResolveOverheadPolicy
{
    public function __invoke(Assembly $assembly): OverheadPolicyResolution
    {
        $ownPolicy = $this->active(OverheadScopeType::Assembly, $assembly->id);
        if ($ownPolicy !== null) {
            return new OverheadPolicyResolution($ownPolicy, OverheadResolutionMethod::Assembly);
        }

        if ($assembly->assembly_group_id !== null) {
            $groupPolicy = $this->active(OverheadScopeType::AssemblyGroup, $assembly->assembly_group_id);
            if ($groupPolicy !== null) {
                return new OverheadPolicyResolution($groupPolicy, OverheadResolutionMethod::AssemblyGroup);
            }
        }

        $companyPolicy = $this->active(OverheadScopeType::Company, null);
        if ($companyPolicy !== null) {
            return new OverheadPolicyResolution($companyPolicy, OverheadResolutionMethod::Company);
        }

        return new OverheadPolicyResolution(null, OverheadResolutionMethod::None);
    }

    private function active(OverheadScopeType $scopeType, ?int $scopeId): ?OverheadPolicy
    {
        return OverheadPolicy::query()
            ->forScope($scopeType, $scopeId)
            ->where('status', OverheadPolicyStatus::Active)
            ->first();
    }
}
