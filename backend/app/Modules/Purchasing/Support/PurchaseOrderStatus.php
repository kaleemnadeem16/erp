<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

enum PurchaseOrderStatus: string
{
    case Draft = 'DRAFT';
    case Issued = 'ISSUED';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';
}
