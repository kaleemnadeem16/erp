<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\Assembly;
use Illuminate\Support\Facades\DB;

final class UpdateAssembly
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Assembly $assembly, array $attributes): Assembly
    {
        return DB::transaction(function () use ($assembly, $attributes): Assembly {
            $assembly->update($attributes);

            return $assembly->refresh();
        });
    }
}
