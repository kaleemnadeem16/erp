<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\RateDimension;
use Illuminate\Support\Facades\DB;

final class UpdateRateDimension
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(RateDimension $rateDimension, array $attributes): RateDimension
    {
        return DB::transaction(function () use ($rateDimension, $attributes): RateDimension {
            $rateDimension->update($attributes);

            return $rateDimension->refresh();
        });
    }
}
