<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\Formulation;
use Illuminate\Support\Facades\DB;

final class UpdateFormulation
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(Formulation $formulation, array $attributes): Formulation
    {
        return DB::transaction(function () use ($formulation, $attributes): Formulation {
            $formulation->update($attributes);

            return $formulation->refresh();
        });
    }
}
