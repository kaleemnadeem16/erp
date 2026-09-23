<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\RateDimension;
use Illuminate\Support\Facades\DB;

final class ArchiveRateDimension
{
    public function __invoke(RateDimension $rateDimension): void
    {
        DB::transaction(fn () => $rateDimension->delete());
    }
}
