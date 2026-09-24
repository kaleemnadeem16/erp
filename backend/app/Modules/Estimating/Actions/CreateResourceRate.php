<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\ResourceRate;
use Illuminate\Support\Facades\DB;

final class CreateResourceRate
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): ResourceRate
    {
        return DB::transaction(fn () => ResourceRate::create($attributes)->refresh());
    }
}
