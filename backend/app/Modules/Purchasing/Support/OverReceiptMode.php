<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

enum OverReceiptMode: string
{
    case Block = 'BLOCK';
    case AllowWithinTolerance = 'ALLOW_WITHIN_TOLERANCE';
}
