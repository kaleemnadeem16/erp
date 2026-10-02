<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Support\MaterialVariancePolicyStatus;
use Illuminate\Support\Facades\DB;

final class DeleteMaterialVariancePolicy
{
    public function __invoke(MaterialVariancePolicy $policy): void
    {
        if ($policy->status !== MaterialVariancePolicyStatus::Draft) {
            throw new DomainInvariantException('Only a DRAFT variance policy may be deleted; ACTIVE and SUPERSEDED versions are kept.', 'status');
        }

        DB::transaction(fn () => $policy->delete());
    }
}
