<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\RateDimensionValue;
use Illuminate\Support\Facades\DB;

final class DeleteRateDimensionValue
{
    public function __invoke(RateDimensionValue $rateDimensionValue): void
    {
        DB::transaction(fn () => $rateDimensionValue->delete());
    }
}
