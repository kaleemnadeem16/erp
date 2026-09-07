<?php

declare(strict_types=1);

namespace App\Modules\Imports\Support;

enum ImportRowStatus: string
{
    case Pending = 'PENDING';
    case Valid = 'VALID';
    case Invalid = 'INVALID';
    case Committed = 'COMMITTED';
}
