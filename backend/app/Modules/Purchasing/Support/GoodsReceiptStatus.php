<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

enum GoodsReceiptStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Reversed = 'REVERSED';
}
