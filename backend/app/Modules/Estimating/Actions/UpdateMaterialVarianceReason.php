<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\MaterialVarianceReason;
use Illuminate\Support\Facades\DB;

/**
 * A reason is retired (`active` = false), never deleted: an adopted plan snapshots the reason it used,
 * and a company's history must keep reading. An inactive reason stays visible but is not offered.
 */
final class UpdateMaterialVarianceReason
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(MaterialVarianceReason $reason, array $attributes): MaterialVarianceReason
    {
        return DB::transaction(function () use ($reason, $attributes): MaterialVarianceReason {
            $reason->update($attributes);

            return $reason->refresh();
        });
    }
}
