<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

enum TaxVersionKind: string
{
    case Component = 'component';
    case Rate = 'rate';
    case Rule = 'rule';
    case Group = 'group';
}
