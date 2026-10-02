<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** Which level of ADR 0054's hierarchy supplied the policy. `None` is legitimate: the built-in default applies. */
enum VarianceResolutionMethod: string
{
    case Item = 'ITEM';
    case Category = 'CATEGORY';
    case Company = 'COMPANY';
    case None = 'NONE';
}
