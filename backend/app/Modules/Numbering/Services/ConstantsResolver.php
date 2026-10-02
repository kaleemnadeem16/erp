<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Modules\Numbering\Models\NumberingConstant;

/** The company's constants in force now: code => value. */
final class ConstantsResolver
{
    /** @return array<string, string> */
    public function active(): array
    {
        return NumberingConstant::query()->where('status', 'ACTIVE')->whereNotNull('value')->pluck('value', 'code')->all();
    }
}
