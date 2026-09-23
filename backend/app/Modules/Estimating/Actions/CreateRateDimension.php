<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\RateDimension;
use Illuminate\Support\Facades\DB;

final class CreateRateDimension
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): RateDimension
    {
        return DB::transaction(fn () => RateDimension::create($attributes)->refresh());
    }
}
