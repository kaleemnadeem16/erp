<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\AssemblyGroup;
use Illuminate\Support\Facades\DB;

final class CreateAssemblyGroup
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): AssemblyGroup
    {
        return DB::transaction(fn () => AssemblyGroup::create($attributes)->refresh());
    }
}
