<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support\Review;

enum ReviewProfile: string
{
    case Commercial = 'COMMERCIAL';
    case Operational = 'OPERATIONAL';
}
