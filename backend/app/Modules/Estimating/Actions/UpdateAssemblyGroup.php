<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\AssemblyGroup;
use Illuminate\Support\Facades\DB;

final class UpdateAssemblyGroup
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(AssemblyGroup $assemblyGroup, array $attributes): AssemblyGroup
    {
        return DB::transaction(function () use ($assemblyGroup, $attributes): AssemblyGroup {
            $assemblyGroup->update($attributes);

            return $assemblyGroup->refresh();
        });
    }
}
