<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\RateDimension;
use App\Modules\Estimating\Models\RateDimensionValue;
use Illuminate\Support\Facades\DB;

final class CreateRateDimensionValue
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(RateDimension $rateDimension, array $attributes): RateDimensionValue
    {
        return DB::transaction(fn () => $rateDimension->values()->create($attributes)->refresh());
    }
}
