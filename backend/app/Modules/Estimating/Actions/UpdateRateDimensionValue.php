<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\RateDimensionValue;
use Illuminate\Support\Facades\DB;

final class UpdateRateDimensionValue
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(RateDimensionValue $rateDimensionValue, array $attributes): RateDimensionValue
    {
        return DB::transaction(function () use ($rateDimensionValue, $attributes): RateDimensionValue {
            $rateDimensionValue->update($attributes);

            return $rateDimensionValue->refresh();
        });
    }
}
