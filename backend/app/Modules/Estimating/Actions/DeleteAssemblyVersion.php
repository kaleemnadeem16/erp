<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\AssemblyVersionStatus;
use Illuminate\Support\Facades\DB;

final class DeleteAssemblyVersion
{
    public function __invoke(AssemblyVersion $version): void
    {
        if ($version->status !== AssemblyVersionStatus::Draft) {
            throw new DomainInvariantException(
                'Only a DRAFT assembly version may be deleted; ACTIVE and SUPERSEDED versions are kept.',
                field: 'status',
            );
        }

        DB::transaction(fn () => $version->delete());
    }
}
