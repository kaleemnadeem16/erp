<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Support\AssemblyStatus;
use Illuminate\Support\Facades\DB;

final class ArchiveAssembly
{
    public function __invoke(Assembly $assembly): void
    {
        DB::transaction(fn () => $assembly->update(['status' => AssemblyStatus::Archived]));
    }
}
