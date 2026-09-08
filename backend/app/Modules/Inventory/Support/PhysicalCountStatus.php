<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum PhysicalCountStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Cancelled = 'CANCELLED';
}
