<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\AssemblyGroup;
use Illuminate\Support\Facades\DB;

/**
 * AssemblyGroup has no status column (flat classification master data, same
 * as RateDimension) — "archiving" is a hard delete, which the composite FK
 * on assemblies.assembly_group_id blocks while any assembly still refers to
 * this group.
 */
final class ArchiveAssemblyGroup
{
    public function __invoke(AssemblyGroup $assemblyGroup): void
    {
        DB::transaction(fn () => $assemblyGroup->delete());
    }
}
