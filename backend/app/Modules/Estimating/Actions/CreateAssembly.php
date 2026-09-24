<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\Assembly;
use Illuminate\Support\Facades\DB;

final class CreateAssembly
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): Assembly
    {
        return DB::transaction(fn () => Assembly::create($attributes)->refresh());
    }
}
