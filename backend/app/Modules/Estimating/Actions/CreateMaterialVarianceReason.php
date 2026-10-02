<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\MaterialVarianceReason;
use Illuminate\Support\Facades\DB;

final class CreateMaterialVarianceReason
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): MaterialVarianceReason
    {
        return DB::transaction(fn () => MaterialVarianceReason::create($attributes)->refresh());
    }
}
