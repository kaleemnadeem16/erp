<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

enum SourceKind: string
{
    case Item = 'ITEM';
    case Assembly = 'ASSEMBLY';
    case Manual = 'MANUAL';
}
