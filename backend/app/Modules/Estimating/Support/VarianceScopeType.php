<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** ADR 0054's resolution order: item override → nearest category ancestor → company default. */
enum VarianceScopeType: string
{
    case Company = 'COMPANY';
    case Category = 'CATEGORY';
    case Item = 'ITEM';
}
