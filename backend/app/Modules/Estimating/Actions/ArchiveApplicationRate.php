<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\ApplicationRate;
use Illuminate\Support\Facades\DB;

final class ArchiveApplicationRate
{
    public function __invoke(ApplicationRate $applicationRate): void
    {
        DB::transaction(fn () => $applicationRate->delete());
    }
}
