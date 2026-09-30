<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Support\AssemblyStatus;
use Illuminate\Support\Facades\DB;

final class ArchiveAssembly
{
    public function __invoke(Assembly $assembly, User $actor): void
    {
        if (! $actor->can('estimating.assembly.manage')) {
            throw new DomainInvariantException('The actor is not permitted to manage assemblies.');
        }

        DB::transaction(fn () => $assembly->update(['status' => AssemblyStatus::Archived]));
    }
}
